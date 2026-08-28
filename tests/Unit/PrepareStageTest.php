<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\Manifest;
use FictionDrafts\Backup\Preflight;
use FictionDrafts\Backup\Stages\DatabaseStage;
use FictionDrafts\Backup\Stages\FileScanStage;
use FictionDrafts\Backup\Stages\PrepareStage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The gate between measuring a backup and writing one.
 *
 * The stage's placement is the substance: it runs after the scan, because the
 * only honest number to refuse on is the one the scan just finished measuring.
 * These tests feed it a real scan summary rather than a number chosen to make
 * them pass.
 */
final class PrepareStageTest extends TestCase {

	private string $storageDir = '';

	private string $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->storageDir = sys_get_temp_dir() . '/fd-prep-' . bin2hex( random_bytes( 6 ) );

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

	private function job( BackupProfile $profile = BackupProfile::Full ): BackupJob {
		return new BackupJob( $this->uuid, $profile, createdAt: '2026-08-28 12:00:00' );
	}

	private function stage( ?int $free = null ): PrepareStage {
		return new PrepareStage(
			$this->storage(),
			new Preflight(
				$this->storage(),
				null === $free ? null : static fn ( string $path ): float => (float) $free
			),
			new Manifest()
		);
	}

	/**
	 * A dump and a scan summary, as the two stages before this one leave them.
	 */
	private function seedWorkingDir( int $dumpBytes = 100, int $files = 4, int $scanBytes = 900, int $links = 2 ): void {
		file_put_contents( $this->workingDir() . '/' . DatabaseStage::OUTPUT, str_repeat( 'x', $dumpBytes ) );

		file_put_contents(
			$this->workingDir() . '/' . FileScanStage::SUMMARY,
			(string) wp_json_encode(
				[
					'files'    => $files,
					'bytes'    => $scanBytes,
					'symlinks' => $links,
				]
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		$manifest = Manifest::read( $this->workingDir() . '/' . Manifest::FILENAME );

		$this->assertIsArray( $manifest, 'PrepareStage wrote no manifest' );

		return $manifest;
	}

	/** ISC-366 */
	public function test_it_applies_to_every_job(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertTrue( $this->stage()->appliesTo( $this->job( $profile ) ) );
		}
	}

	/** ISC-361, ISC-362 */
	public function test_it_fails_the_job_when_there_is_not_room(): void {
		$this->seedWorkingDir( 100, 4, 900 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/free disk space/' );

		// 1,000 bytes of content needs 1,200; 1,000 is short.
		$this->stage( 1000 )->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );
	}

	/** ISC-363 — the control: the same job with room passes and completes */
	public function test_it_completes_when_there_is_room(): void {
		$this->seedWorkingDir( 100, 4, 900 );

		$result = $this->stage( 1200 )->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertTrue( $result->complete );
	}

	/** ISC-365 — the figure comes from the dump and the scan, not a guess */
	public function test_the_required_size_is_the_dump_plus_the_scanned_bytes(): void {
		$this->seedWorkingDir( 512, 4, 2048 );

		$this->stage( PHP_INT_MAX >> 4 )->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertSame( 512 + 2048, $this->manifest()['total_bytes'] );
	}

	/** ISC-346, ISC-348 — the counts the scan recorded reach the manifest */
	public function test_the_manifest_carries_the_scan_counts(): void {
		$this->seedWorkingDir( 100, 41, 900, 3 );

		$this->stage( PHP_INT_MAX >> 4 )->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$manifest = $this->manifest();

		$this->assertSame( 41, $manifest['file_count'] );
		$this->assertSame( 3, $manifest['skipped_symlinks'] );
	}

	/** ISC-367 — a database-only job has no scan summary at all */
	public function test_a_job_that_never_scanned_reports_zero_files(): void {
		file_put_contents( $this->workingDir() . '/' . DatabaseStage::OUTPUT, str_repeat( 'x', 64 ) );

		$result = $this->stage( PHP_INT_MAX >> 4 )->run(
			$this->job( BackupProfile::DatabaseOnly ),
			StageCursor::start(),
			new TimeBudget( 20 )
		);

		$this->assertTrue( $result->complete );

		$manifest = $this->manifest();

		$this->assertSame( 0, $manifest['file_count'] );
		$this->assertSame( 64, $manifest['total_bytes'] );
	}

	/** ISC-350 — the manifest lands where ArchiveStage already looks for it */
	public function test_the_manifest_lands_in_the_working_directory(): void {
		$this->seedWorkingDir();

		$this->stage( PHP_INT_MAX >> 4 )->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertFileExists( $this->workingDir() . '/' . Manifest::FILENAME );
	}

	/** ISC-352 — the in-archive copy cannot carry its own checksums */
	public function test_the_manifest_it_writes_has_an_empty_volume_ledger(): void {
		$this->seedWorkingDir();

		$this->stage( PHP_INT_MAX >> 4 )->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertSame( [], $this->manifest()['volumes'] );
	}

	/** ISC-362 — a refused job leaves no manifest claiming it was fine */
	public function test_a_refused_job_writes_no_manifest(): void {
		$this->seedWorkingDir( 100, 4, 900 );

		try {
			$this->stage( 1000 )->run( $this->job(), StageCursor::start(), new TimeBudget( 20 ) );
		} catch ( RuntimeException $refusal ) {
			unset( $refusal );
		}

		$this->assertFileDoesNotExist( $this->workingDir() . '/' . Manifest::FILENAME );
	}

	/** ISC-366 — one step, and nothing to resume */
	public function test_it_completes_in_a_single_step_at_a_zero_second_budget(): void {
		$this->seedWorkingDir();

		$result = $this->stage( PHP_INT_MAX >> 4 )->run( $this->job(), StageCursor::start(), new TimeBudget( 0 ) );

		$this->assertTrue( $result->complete );
	}
}
