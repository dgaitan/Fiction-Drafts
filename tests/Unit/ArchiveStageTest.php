<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Archive\ArchiveWriterFactory;
use FictionDrafts\Backup\Manifest;
use FictionDrafts\Backup\Stages\ArchiveStage;
use FictionDrafts\Backup\Stages\FileScanStage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The archive, and the two failure modes that are silent when they happen:
 * a file lost at a volume boundary, and a file added twice across a resume.
 *
 * Both are checked by comparing the union of the volumes against the scan,
 * because a check that only looks at one volume cannot see either of them.
 *
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
 * -- base64 is how a non-UTF-8 path travels through JSON; see FileScanStage.
 */
final class ArchiveStageTest extends TestCase {

	private TempTree $site;

	private string $storageDir = '';

	private string $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->site       = new TempTree( 'fd-arcsite' );
		$this->storageDir = sys_get_temp_dir() . '/fd-arc-store-' . bin2hex( random_bytes( 6 ) );

		mkdir( $this->storageDir, 0777, true );
	}

	protected function tearDown(): void {
		$this->site->remove();
		TempTree::removeTree( $this->storageDir );

		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $options Per-job choices.
	 */
	private function job( BackupProfile $profile = BackupProfile::Full, array $options = [] ): BackupJob {
		return new BackupJob( $this->uuid, $profile, options: $options, createdAt: '2026-08-28 12:00:00' );
	}

	private function storage(): StorageLocator {
		return new StorageLocator( $this->storageDir );
	}

	private function workingDir(): string {
		$dir = $this->storageDir . '/' . sanitize_key( $this->uuid );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		return $dir;
	}

	private function stage( ?SettingsRepository $settings = null ): ArchiveStage {
		return new ArchiveStage(
			$this->storage(),
			new ArchiveWriterFactory( true ),
			$settings,
			$this->site->root
		);
	}

	/**
	 * Run the scan first, so the archive reads a list the scan actually wrote.
	 */
	private function scan( BackupJob $job ): void {
		$stage  = new FileScanStage( $this->storage(), null, $this->site->root );
		$cursor = StageCursor::start();

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( 20 ) );
			$cursor = $result->cursor;
		} while ( ! $result->complete );
	}

	/**
	 * @return array{steps: int, volumes: array<int, string>, entries: array<int, string>}
	 */
	private function drive( BackupJob $job, int $seconds ): array {
		$stage  = $this->stage();
		$cursor = StageCursor::start();
		$steps  = 0;

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( $seconds ) );
			$cursor = $result->cursor;
			++$steps;

			$this->assertLessThan( 2000, $steps, 'the archive must terminate' );
		} while ( ! $result->complete );

		$volumes = $this->volumes();

		return [
			'steps'   => $steps,
			'volumes' => $volumes,
			'entries' => $this->entriesAcross( $volumes ),
		];
	}

	/**
	 * @return array<int, string>
	 */
	private function volumes(): array {
		$found = glob( $this->storageDir . '/fiction-drafts-*-part*.zip' );

		sort( $found, SORT_STRING );

		return array_values( (array) $found );
	}

	/**
	 * Every entry across every volume, in volume order.
	 *
	 * Deliberately not deduplicated — a duplicate is one of the two things
	 * this file exists to catch.
	 *
	 * @param  array<int, string> $volumes Volume paths.
	 * @return array<int, string>
	 */
	private function entriesAcross( array $volumes ): array {
		$names = [];

		foreach ( $volumes as $volume ) {
			$zip = new ZipArchive();

			$this->assertTrue( true === $zip->open( $volume ), 'every volume must be a valid zip: ' . $volume );

			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				// FL_ENC_RAW, because a name that is not valid UTF-8 is stored
				// with the CP437 flag set and getNameIndex() would hand back a
				// transcoded version of it.  What matters is the bytes that
				// went in.
				$names[] = (string) $zip->getNameIndex( $index, ZipArchive::FL_ENC_RAW );
			}

			$zip->close();
		}

		return $names;
	}

	/**
	 * @return array<int, string>
	 */
	private function scannedPaths(): array {
		$paths = [];
		$file  = $this->workingDir() . '/' . FileScanStage::OUTPUT;

		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$decoded = json_decode( (string) $line, true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['p'] ) ) {
				continue;
			}

			$paths[] = isset( $decoded['b'] )
				? (string) base64_decode( (string) $decoded['p'], true )
				: (string) $decoded['p'];
		}

		return $paths;
	}

	/** ISC-296 */
	public function test_the_stage_identifies_itself_as_archive(): void {
		$this->assertSame( 'archive', $this->stage()->id() );
	}

	/** ISC-297, ISC-299 */
	public function test_a_database_only_job_archives_the_dump_alone(): void {
		file_put_contents( $this->workingDir() . '/database.sql', 'SET NAMES utf8mb4;' );

		$driven = $this->drive( $this->job( BackupProfile::DatabaseOnly ), 20 );

		$this->assertSame( [ 'database.sql' ], $driven['entries'] );
	}

	/** ISC-298 — the manifest Sprint 5 writes needs no change here */
	public function test_a_manifest_present_in_the_working_directory_is_archived(): void {
		file_put_contents( $this->workingDir() . '/database.sql', 'SET NAMES utf8mb4;' );
		file_put_contents( $this->workingDir() . '/manifest.json', '{"a":1}' );

		$driven = $this->drive( $this->job( BackupProfile::DatabaseOnly ), 20 );

		$this->assertSame( [ 'database.sql', 'manifest.json' ], $driven['entries'] );
	}

	/** ISC-300, ISC-307 */
	public function test_every_scanned_file_reaches_the_archive_exactly_once(): void {
		foreach ( range( 1, 40 ) as $index ) {
			$this->site->file( sprintf( 'wp-content/uploads/f%03d.bin', $index ), str_repeat( 'x', 1024 ) );
		}

		$job = $this->job();

		$this->scan( $job );

		$driven = $this->drive( $job, 20 );

		$this->assertSame( $this->scannedPaths(), $driven['entries'] );
	}

	/** ISC-289 */
	public function test_no_entry_name_appears_twice(): void {
		foreach ( range( 1, 30 ) as $index ) {
			$this->site->file( sprintf( 'wp-content/uploads/f%03d.bin', $index ), str_repeat( 'x', 1024 ) );
		}

		$job = $this->job();

		$this->scan( $job );

		$entries = $this->drive( $job, 0 )['entries'];

		$this->assertSame( count( $entries ), count( array_unique( $entries ) ) );
	}

	/** ISC-302 — resumed at a zero-second budget, the listing still matches */
	public function test_a_zero_second_budget_produces_the_same_listing_as_a_full_one(): void {
		foreach ( range( 1, 25 ) as $index ) {
			$this->site->file( sprintf( 'wp-content/uploads/f%03d.bin', $index ), str_repeat( 'x', 2048 ) );
		}

		$job = $this->job();

		$this->scan( $job );

		$fast = $this->drive( $job, 20 );
		$slow = $this->drive( $job, 0 );

		$this->assertSame( 1, $fast['steps'] );
		$this->assertGreaterThan( 1, $slow['steps'] );
		$this->assertSame( $fast['entries'], $slow['entries'] );
	}

	/** ISC-288 — a step repeated because its cursor never persisted */
	public function test_a_repeated_step_does_not_duplicate_its_entries(): void {
		foreach ( range( 1, 20 ) as $index ) {
			$this->site->file( sprintf( 'wp-content/uploads/f%03d.bin', $index ), str_repeat( 'x', 512 ) );
		}

		$job = $this->job();

		$this->scan( $job );

		$stage = $this->stage();

		$first = $stage->run( $job, StageCursor::start(), new TimeBudget( 0 ) );
		// The work of this step reaches disk; its cursor is thrown away.
		$stage->run( $job, $first->cursor, new TimeBudget( 0 ) );

		$cursor = $first->cursor;

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( 0 ) );
			$cursor = $result->cursor;
		} while ( ! $result->complete );

		$entries = $this->entriesAcross( $this->volumes() );

		$this->assertSame( $this->scannedPaths(), $entries );
	}

	/**
	 * ISC-301 — and why the last two are there.
	 *
	 * `vline`/`voffset` are the line the current volume began at.  Without
	 * them, a volume that lost buffered writes after its cursor was committed
	 * cannot be rebuilt: `truncateTo()` finds fewer entries than the cursor
	 * claims, and there is no way back to the volume's first file.
	 */
	public function test_the_cursor_carries_the_position_and_the_volumes_own_start(): void {
		$this->site->file( 'a.bin', str_repeat( 'x', 100 ) );
		$this->site->file( 'b.bin', str_repeat( 'x', 100 ) );

		$job = $this->job();

		$this->scan( $job );

		$first = $this->stage()->run( $job, StageCursor::start(), new TimeBudget( 0 ) );

		$this->assertSame(
			[ 'line', 'offset', 'volume', 'entries', 'vline', 'voffset' ],
			array_keys( $first->cursor->toArray() )
		);
	}

	/** ISC-305, ISC-306, ISC-307 — volumes, and the boundary */
	public function test_a_one_megabyte_cap_splits_three_megabytes_into_at_least_three_volumes(): void {
		foreach ( range( 1, 12 ) as $index ) {
			// Incompressible, so the cap is reached by real bytes rather than
			// by a projection that compression would undercut.
			$this->site->file(
				sprintf( 'wp-content/uploads/f%03d.bin', $index ),
				random_bytes( 262144 )
			);
		}

		$job = $this->job( BackupProfile::Full, [ BackupJob::OPTION_MAX_VOLUME_BYTES => 1048576 ] );

		$this->scan( $job );

		$stage  = $this->stage();
		$cursor = StageCursor::start();

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( 20 ) );
			$cursor = $result->cursor;
		} while ( ! $result->complete );

		$volumes = $this->volumes();

		$this->assertGreaterThanOrEqual( 3, count( $volumes ) );
		$this->assertSame( 'fiction-drafts-2026-08-28-aaaaaaaa-part01.zip', basename( $volumes[0] ) );
		$this->assertSame( 'fiction-drafts-2026-08-28-aaaaaaaa-part02.zip', basename( $volumes[1] ) );
		$this->assertSame( 'fiction-drafts-2026-08-28-aaaaaaaa-part03.zip', basename( $volumes[2] ) );

		// The boundary test: the union across volumes is the scan, exactly.
		$this->assertSame( $this->scannedPaths(), $this->entriesAcross( $volumes ) );
	}

	/** ISC-308 — a file bigger than a whole volume still gets archived */
	public function test_a_file_larger_than_the_volume_cap_is_placed_in_its_own_volume(): void {
		$this->site->file( 'small.bin', random_bytes( 4096 ) );
		$this->site->file( 'huge.bin', random_bytes( 2097152 ) );

		$job = $this->job( BackupProfile::Full, [ BackupJob::OPTION_MAX_VOLUME_BYTES => 1048576 ] );

		$this->scan( $job );

		$driven = $this->drive( $job, 20 );

		$this->assertContains( 'huge.bin', $driven['entries'] );
		// And nothing was lost making room for it.
		$this->assertSame( $this->scannedPaths(), $driven['entries'] );
	}

	/** ISC-303, ISC-304 — the step is bounded in bytes, not only in time */
	public function test_a_step_stops_once_it_has_added_its_byte_budget(): void {
		foreach ( range( 1, 20 ) as $index ) {
			$this->site->file( sprintf( 'f%03d.bin', $index ), str_repeat( 'x', 65536 ) );
		}

		$job = $this->job();

		$this->scan( $job );

		fiction_drafts_test_add_filter(
			ArchiveStage::FILTER_STEP_BYTES,
			static fn (): int => 131072
		);

		// A twenty-second budget with plenty of time left: only the byte cap
		// can stop this step.
		$first = $this->stage()->run( $job, StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertFalse( $first->complete );
		$this->assertLessThan( 20, $first->processed );
	}

	/** ISC-310 */
	public function test_a_file_that_vanished_after_the_scan_is_skipped(): void {
		$this->site->file( 'kept.txt', 'a' );
		$this->site->file( 'ghost.txt', 'b' );

		$job = $this->job();

		$this->scan( $job );

		unlink( $this->site->path( 'ghost.txt' ) );

		$driven = $this->drive( $job, 20 );

		$this->assertSame( [ 'kept.txt' ], $driven['entries'] );
	}

	/** ISC-311 */
	public function test_the_reported_total_is_the_scan_line_count(): void {
		$this->site->file( 'a.txt', 'a' );
		$this->site->file( 'b.txt', 'b' );

		$job = $this->job();

		$this->scan( $job );

		$result = $this->stage()->run( $job, StageCursor::start(), new TimeBudget( 20 ) );

		$this->assertSame( count( $this->scannedPaths() ), $result->total );
	}

	/** ISC-312 — a volume on disk is a valid zip at the end of every step */
	public function test_every_intermediate_volume_opens(): void {
		foreach ( range( 1, 20 ) as $index ) {
			$this->site->file( sprintf( 'f%03d.bin', $index ), str_repeat( 'x', 1024 ) );
		}

		$job = $this->job();

		$this->scan( $job );

		$stage  = $this->stage();
		$cursor = StageCursor::start();

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( 0 ) );
			$cursor = $result->cursor;

			// entriesAcross() asserts that every volume opens.
			$this->entriesAcross( $this->volumes() );
		} while ( ! $result->complete );

		$this->assertSame( $this->scannedPaths(), $this->entriesAcross( $this->volumes() ) );
	}

	/** ISC-314, ISC-315 */
	public function test_no_entry_escapes_the_root_or_names_a_volume(): void {
		$this->site->file( 'wp-content/themes/x/style.css', 'a' );

		$job = $this->job();

		$this->scan( $job );

		foreach ( $this->drive( $job, 20 )['entries'] as $entry ) {
			$this->assertStringStartsNotWith( '/', $entry );
			$this->assertStringNotContainsString( '../', $entry );
			$this->assertStringNotContainsString( '-part0', $entry );
		}
	}

	/**
	 * The volume the cursor over-counts: a committed row, uncommitted pages.
	 *
	 * Simulated by truncating the volume on disk after a step persisted its
	 * cursor — which is what a power loss between the two leaves behind.  The
	 * stage must rebuild the volume from `vline` rather than advancing past
	 * files it never wrote.
	 */
	public function test_a_volume_shorter_than_its_cursor_is_rebuilt_not_skipped(): void {
		foreach ( range( 1, 20 ) as $index ) {
			$this->site->file( sprintf( 'f%03d.bin', $index ), random_bytes( 32768 ) );
		}

		$job = $this->job( BackupProfile::Full, [ BackupJob::OPTION_MAX_VOLUME_BYTES => 65536 ] );

		$this->scan( $job );

		$stage  = $this->stage();
		$cursor = StageCursor::start();

		// Far enough in to be on a later volume.
		for ( $step = 0; $step < 6; ++$step ) {
			$result = $stage->run( $job, $cursor, new TimeBudget( 0 ) );
			$cursor = $result->cursor;
		}

		$this->assertGreaterThan( 1, $cursor->getInt( 'volume' ), 'the run should have rolled over by now' );

		// Lose the current volume's pages entirely.
		$volumes = $this->volumes();
		$current = $volumes[ $cursor->getInt( 'volume' ) - 1 ];

		unlink( $current );

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( 0 ) );
			$cursor = $result->cursor;
		} while ( ! $result->complete );

		$this->assertSame( $this->scannedPaths(), $this->entriesAcross( $this->volumes() ) );
	}

	/**
	 * ISC-329 — a filename that is not valid UTF-8 still reaches the archive.
	 *
	 * `json_encode()` returns false for one, and `(string) false` is an empty
	 * line: the file would be absent from `files.jsonl`, absent from the
	 * archive, and invisible to every check that compares the two, because it
	 * was never in the list either.  Latin-1 names in `wp-content/uploads` are
	 * ordinary on a site migrated from an older host.
	 *
	 * APFS refuses to create such a name, so the source half of the round trip
	 * cannot be staged here.  What is tested is the half that decides what
	 * lands in the archive: a `files.jsonl` line carrying a base64 path must
	 * produce an entry under the decoded name.
	 */
	public function test_a_base64_path_is_archived_under_its_decoded_name(): void {
		$this->site->file( 'plain.jpg', 'photo' );

		$job = $this->job();

		$this->scan( $job );

		$list = $this->workingDir() . '/' . FileScanStage::OUTPUT;

		file_put_contents(
			$list,
			(string) wp_json_encode(
				[
					'p' => base64_encode( "caf\xe9.jpg" ),
					'b' => 1,
					's' => 5,
					'a' => base64_encode( $this->site->path( 'plain.jpg' ) ),
				]
			) . "\n"
		);

		$driven = $this->drive( $job, 20 );

		$this->assertSame( [ "caf\xe9.jpg" ], $driven['entries'] );
	}

	/**
	 * The encode half, on a filesystem that will host the name.
	 */
	public function test_a_non_utf8_filename_survives_the_scan(): void {
		$name = "caf\xe9.jpg";

		// Silenced on purpose: APFS raises "Illegal byte sequence" for this
		// name, and the point of the call is to find out whether it can be
		// staged at all.
		$staged = @file_put_contents( $this->site->path( $name ), 'photo' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- probing the filesystem's tolerance is the assertion.

		if ( false === $staged ) {
			$this->markTestSkipped( 'This filesystem enforces UTF-8 filenames; the case is a Linux one.' );
		}

		$job = $this->job();

		$this->scan( $job );

		$driven = $this->drive( $job, 20 );

		$this->assertContains( $name, $this->scannedPaths() );
		$this->assertSame( $this->scannedPaths(), $driven['entries'] );
	}
	/**
	 * ISC-350 — the manifest PrepareStage leaves in the working directory is
	 * picked up with no change to this stage. EXTRAS has named it since
	 * Sprint 4; this is the check that the name still matches what is written.
	 */
	public function test_a_manifest_in_the_working_directory_reaches_the_archive(): void {
		$job = $this->job();

		$this->scan( $job );

		file_put_contents( $this->workingDir() . '/' . Manifest::FILENAME, '{"schema":1}' );

		$driven = $this->drive( $job, 20 );

		$this->assertContains( Manifest::FILENAME, $driven['entries'] );
	}

	/** ISC-350 — the control: no manifest written, none in the archive */
	public function test_no_manifest_entry_when_prepare_never_ran(): void {
		$job = $this->job();

		$this->scan( $job );

		$driven = $this->drive( $job, 20 );

		$this->assertNotContains( Manifest::FILENAME, $driven['entries'] );
	}
}
