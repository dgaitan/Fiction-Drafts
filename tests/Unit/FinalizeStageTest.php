<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Backup\Manifest;
use FictionDrafts\Backup\Stages\DatabaseStage;
use FictionDrafts\Backup\Stages\FileScanStage;
use FictionDrafts\Backup\Stages\FinalizeStage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\InMemoryVolumeStore;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use ZipArchive;
use RuntimeException;

/**
 * Hashing, the ledger, the sidecar manifest, and the cleanup — in that order.
 *
 * The order is the part worth testing. Removing the working directory before
 * reading the manifest out of it produces a sidecar that looks exactly like a
 * real one and is a skeleton, which nobody discovers until they need it.
 */
final class FinalizeStageTest extends TestCase {

	private string $storageDir = '';

	private string $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private InMemoryVolumeStore $volumes;

	private InMemoryJobStore $jobs;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->storageDir = sys_get_temp_dir() . '/fd-final-' . bin2hex( random_bytes( 6 ) );
		$this->volumes    = new InMemoryVolumeStore();
		$this->jobs       = new InMemoryJobStore();

		mkdir( $this->workingDir(), 0777, true );
	}

	protected function tearDown(): void {
		TempTree::removeTree( $this->storageDir );

		parent::tearDown();
	}

	private function storage(): StorageLocator {
		return new StorageLocator( $this->storageDir );
	}

	private function workingDir(): string {
		return $this->storageDir . '/' . sanitize_key( $this->uuid );
	}

	private function job(): BackupJob {
		return new BackupJob( $this->uuid, BackupProfile::Full, id: 7, createdAt: '2026-08-28 12:00:00' );
	}

	private function naming(): VolumeNaming {
		return new VolumeNaming( $this->storageDir );
	}

	private function stage(): FinalizeStage {
		return new FinalizeStage( $this->storage(), $this->volumes, $this->jobs );
	}

	/**
	 * Volumes and a working directory, as the stages before this one leave them.
	 *
	 * @param  array<int, string> $bodies One string per volume.
	 * @return array<int, string> Absolute paths, in sequence order.
	 */
	private function seed( array $bodies = [ 'volume one', 'volume two' ], bool $withManifest = true ): array {
		$paths = [];

		foreach ( $bodies as $index => $body ) {
			$path = $this->naming()->pathFor( $this->job(), $index + 1 );

			// Real archives, not files that merely have the name. FinalizeStage
			// reads each volume's central directory to check the entry total
			// against the file count the scan recorded, and a fixture that is
			// not a zip would make that check untestable.
			$zip = new ZipArchive();
			$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
			$zip->addFromString( 'entry-' . ( $index + 1 ) . '.txt', $body );
			$zip->close();

			$paths[] = $path;
		}

		file_put_contents( $this->workingDir() . '/' . DatabaseStage::OUTPUT, 'CREATE TABLE x;' );
		file_put_contents( $this->workingDir() . '/' . FileScanStage::OUTPUT, "{}\n" );

		if ( $withManifest ) {
			Manifest::write(
				$this->workingDir() . '/' . Manifest::FILENAME,
				( new Manifest() )->build( $this->job(), count( $bodies ), 15, 0 )
			);
		}

		return $paths;
	}

	/** ISC-353 */
	public function test_every_volume_is_hashed_with_sha256_of_its_bytes(): void {
		$paths = $this->seed();

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$recorded = $this->volumes->allFor( $this->job() );

		$this->assertCount( 2, $recorded );

		foreach ( $recorded as $index => $volume ) {
			$this->assertSame( hash_file( 'sha256', $paths[ $index ] ), $volume->sha256 );
		}
	}

	/** ISC-353 — the control: a changed byte changes the hash */
	public function test_a_different_volume_produces_a_different_hash(): void {
		$this->seed( [ 'aaaa', 'aaab' ] );

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$recorded = $this->volumes->allFor( $this->job() );

		$this->assertNotSame( $recorded[0]->sha256, $recorded[1]->sha256 );
	}

	/** ISC-355 */
	public function test_each_volume_records_its_size_on_disk(): void {
		$paths = $this->seed( [ str_repeat( 'a', 4000 ), str_repeat( 'b', 90 ) ] );

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$recorded = $this->volumes->allFor( $this->job() );

		$this->assertSame( (int) filesize( $paths[0] ), $recorded[0]->bytes );
		$this->assertSame( (int) filesize( $paths[1] ), $recorded[1]->bytes );
		$this->assertNotSame( $recorded[0]->bytes, $recorded[1]->bytes );
	}

	/** ISC-356 */
	public function test_the_job_size_is_the_sum_of_its_volumes(): void {
		$paths = $this->seed( [ str_repeat( 'a', 4000 ), str_repeat( 'b', 90 ) ] );

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$saved = $this->jobs->findByUuid( $this->uuid );

		$this->assertNotNull( $saved );
		$this->assertSame( (int) filesize( $paths[0] ) + (int) filesize( $paths[1] ), $saved->sizeBytes );
	}

	/** ISC-351, ISC-354 */
	public function test_the_sidecar_manifest_carries_the_volume_ledger(): void {
		$this->seed();

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$sidecar = Manifest::read( $this->naming()->manifestPathFor( $this->job() ) );

		$this->assertIsArray( $sidecar );
		$this->assertCount( 2, $sidecar['volumes'] );
		$this->assertSame(
			$this->volumes->allFor( $this->job() )[0]->sha256,
			$sidecar['volumes'][0]['sha256']
		);
	}

	/** ISC-351 — the sidecar keeps every field the inner copy had */
	public function test_the_sidecar_keeps_the_provenance_fields(): void {
		$this->seed();

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$sidecar = Manifest::read( $this->naming()->manifestPathFor( $this->job() ) );

		$this->assertIsArray( $sidecar );
		$this->assertSame( Manifest::KEYS, array_keys( $sidecar ) );
		$this->assertSame( '2026-08-28 12:00:00', $sidecar['created_at'] );
	}

	/** ISC-373 */
	public function test_it_removes_the_working_directory(): void {
		$this->seed();

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertDirectoryDoesNotExist( $this->workingDir() );
	}

	/** ISC-374, ISC-376 — the volumes survive the cleanup, intact */
	public function test_the_volumes_survive_and_still_hold_their_bytes(): void {
		$paths = $this->seed( [ 'volume one', 'volume two' ] );

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		foreach ( $paths as $path ) {
			$this->assertFileExists( $path );
		}

		$zip = new ZipArchive();

		$this->assertTrue( $zip->open( $paths[0] ) );
		$this->assertSame( 'volume one', $zip->getFromName( 'entry-1.txt' ) );

		$zip->close();
	}

	/** ISC-374 — nothing else belonging to this job is left behind */
	public function test_the_storage_root_holds_only_volumes_and_the_sidecar(): void {
		$this->seed();

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$left = array_values(
			array_diff( (array) scandir( $this->storageDir ), [ '.', '..' ] )
		);

		sort( $left );

		$this->assertSame(
			[
				'fiction-drafts-2026-08-28-aaaaaaaa-manifest.json',
				'fiction-drafts-2026-08-28-aaaaaaaa-part01.zip',
				'fiction-drafts-2026-08-28-aaaaaaaa-part02.zip',
			],
			$left
		);
	}

	/**
	 * ISC-373 — the ordering test. The sidecar is built from the copy inside
	 * the working directory, so if the directory went first the sidecar would
	 * be missing or a skeleton.
	 */
	public function test_the_sidecar_is_written_before_the_working_directory_goes(): void {
		$this->seed();

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$sidecar = Manifest::read( $this->naming()->manifestPathFor( $this->job() ) );

		$this->assertIsArray( $sidecar );
		$this->assertDirectoryDoesNotExist( $this->workingDir() );
		$this->assertNotSame( [], $sidecar['volumes'] );
	}

	/** ISC-360 — a job that produced nothing is a failure, not a success */
	public function test_it_refuses_to_seal_a_backup_with_no_volumes(): void {
		file_put_contents( $this->workingDir() . '/' . DatabaseStage::OUTPUT, 'x' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/no archive file/' );

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );
	}

	/**
	 * ISC-353 — Action Scheduler can run an action twice when the first
	 * request died after the work. The ledger must describe the disk, not
	 * accumulate.
	 */
	public function test_running_twice_leaves_one_row_per_volume(): void {
		$this->seed();

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		// The second run has no working directory; the volumes are still there.
		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertCount( 2, $this->volumes->allFor( $this->job() ) );
	}

	/** ISC-375 */
	public function test_it_applies_to_every_job(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertTrue(
				$this->stage()->appliesTo( new BackupJob( $this->uuid, $profile ) )
			);
		}
	}

	/** ISC-375 — it completes in one step, reporting the volume count */
	public function test_it_completes_in_one_step(): void {
		$this->seed();

		$result = $this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 0 ) );

		$this->assertTrue( $result->complete );
		$this->assertSame( 2, $result->processed );
	}
	/**
	 * ISC-360 — a volume missing from the middle. The sequence walk stops at
	 * the gap, which is right for a crashed run's leftovers and catastrophic
	 * for a backup someone later restores from. The entry total is checked
	 * against a number the archive did not produce: the scan's file count.
	 */
	public function test_it_refuses_when_a_volume_is_missing_from_the_middle(): void {
		$this->seed( [ 'one', 'two', 'three' ] );

		unlink( $this->naming()->pathFor( $this->job(), 2 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/A volume is missing/' );

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );
	}

	/** ISC-360 — the control: the complete set passes the same check */
	public function test_a_complete_volume_set_passes_the_entry_check(): void {
		$this->seed( [ 'one', 'two', 'three' ] );

		$result = $this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertTrue( $result->complete );
		$this->assertSame( 3, $result->processed );
	}

	/**
	 * ISC-351 — the sidecar write is read back before the working directory
	 * goes. It happens immediately after gigabytes of archive, which is when a
	 * disk runs out; destroying the only source of a manifest that was never
	 * written leaves a backup nothing can describe.
	 */
	public function test_it_keeps_the_working_directory_when_the_sidecar_cannot_be_written(): void {
		$this->seed();

		// A directory where the sidecar file needs to be: the write fails and
		// the read-back returns null, without needing a read-only filesystem.
		mkdir( $this->naming()->manifestPathFor( $this->job() ), 0777, true );

		try {
			$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

			$this->fail( 'the stage completed with no manifest describing the backup' );
		} catch ( RuntimeException $failure ) {
			$this->assertStringContainsString( 'could not be saved', $failure->getMessage() );
		}

		$this->assertDirectoryExists( $this->workingDir(), 'the only copy of the manifest was destroyed' );
	}

	/**
	 * ISC-352 — the two copies, diffed. Asserting the key list on each would
	 * pass while a value quietly diverged; this compares them to each other and
	 * allows exactly one key to differ.
	 */
	public function test_the_two_manifests_differ_by_exactly_the_volume_ledger(): void {
		$this->seed();

		$inner = Manifest::read( $this->workingDir() . '/' . Manifest::FILENAME );

		$this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$sidecar = Manifest::read( $this->naming()->manifestPathFor( $this->job() ) );

		$this->assertIsArray( $inner );
		$this->assertIsArray( $sidecar );

		unset( $inner['volumes'], $sidecar['volumes'] );

		$this->assertSame( $inner, $sidecar );
	}
}
