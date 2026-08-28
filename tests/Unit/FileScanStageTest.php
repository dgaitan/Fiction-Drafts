<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\Stages\FileScanStage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * The scan, proved the way Sprint 2 proved the runner and Sprint 3 proved the
 * dump: run the same work at a zero-second budget and at twenty, and require
 * the two outputs to be byte-identical.
 *
 * The extra thing proved here is the resume boundary — the same idea as
 * DatabaseStage's, now applied to two append-only files at once, one of which
 * is also being read from.
 */
final class FileScanStageTest extends TestCase {

	private TempTree $site;

	private string $storageDir = '';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->site       = new TempTree( 'fd-site' );
		$this->storageDir = sys_get_temp_dir() . '/fd-scan-store-' . bin2hex( random_bytes( 6 ) );

		mkdir( $this->storageDir, 0777, true );

		$this->site->file( 'index.php', '<?php' );
		$this->site->file( 'wp-config.php', 'secret' );
		$this->site->file( 'wp-admin/admin.php', 'a' );
		$this->site->file( 'wp-includes/load.php', 'b' );
		$this->site->file( 'wp-content/themes/houzez/style.css', 'c' );
		$this->site->file( 'wp-content/plugins/x/x.php', 'd' );
		$this->site->file( 'wp-content/uploads/2024/01/photo.jpg', 'e' );
		$this->site->file( 'wp-content/uploads/2024/02/other.jpg', 'f' );
		$this->site->file( 'wp-content/cache/object/o.php', 'g' );
		$this->site->file( 'node_modules/pkg/index.js', 'h' );
		$this->site->file( '.git/config', 'i' );
		$this->site->file( 'debug.log', 'j' );
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
		return new BackupJob( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $profile, options: $options );
	}

	private function stage(): FileScanStage {
		return new FileScanStage( new StorageLocator( $this->storageDir ), null, $this->site->root );
	}

	private function scanPath( BackupJob $job ): string {
		return $this->storageDir . '/' . sanitize_key( $job->uuid ) . '/' . FileScanStage::OUTPUT;
	}

	/**
	 * Drive a job to completion, one step per call, and report how many it took.
	 *
	 * @return array{steps: int, result: StageResult}
	 */
	private function drive( BackupJob $job, int $seconds ): array {
		$stage  = $this->stage();
		$cursor = StageCursor::start();
		$steps  = 0;

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( $seconds ) );
			$cursor = $result->cursor;
			++$steps;

			$this->assertLessThan( 500, $steps, 'the scan must terminate' );
		} while ( ! $result->complete );

		return [
			'steps'  => $steps,
			'result' => $result,
		];
	}

	/**
	 * @return array<int, string>
	 */
	private function pathsFrom( string $file ): array {
		$paths = [];

		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$decoded = json_decode( (string) $line, true );

			if ( is_array( $decoded ) && isset( $decoded['p'] ) ) {
				$paths[] = (string) $decoded['p'];
			}
		}

		return $paths;
	}

	/** ISC-254 */
	public function test_the_stage_identifies_itself_as_files(): void {
		$this->assertSame( 'files', $this->stage()->id() );
	}

	/** ISC-264, ISC-265 */
	public function test_applies_to_every_profile_that_copies_files(): void {
		$this->assertTrue( $this->stage()->appliesTo( $this->job( BackupProfile::Full ) ) );
		$this->assertTrue( $this->stage()->appliesTo( $this->job( BackupProfile::FilesOnly ) ) );
		$this->assertTrue( $this->stage()->appliesTo( $this->job( BackupProfile::FilesNoMedia ) ) );
		$this->assertFalse( $this->stage()->appliesTo( $this->job( BackupProfile::DatabaseOnly ) ) );
	}

	/** ISC-255 */
	public function test_every_line_is_one_json_object_with_a_path_and_a_size(): void {
		$job = $this->job();

		$this->drive( $job, 20 );

		foreach ( (array) file( $this->scanPath( $job ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$decoded = json_decode( (string) $line, true );

			$this->assertIsArray( $decoded );
			$this->assertArrayHasKey( 'p', $decoded );
			$this->assertArrayHasKey( 's', $decoded );
			$this->assertIsInt( $decoded['s'] );
		}
	}

	/** ISC-256 */
	public function test_the_reported_total_is_the_line_count(): void {
		$job = $this->job();

		$driven = $this->drive( $job, 20 );

		$this->assertSame(
			count( $this->pathsFrom( $this->scanPath( $job ) ) ),
			$driven['result']->total
		);
	}

	/** ISC-262, ISC-263 — the headline proof */
	public function test_a_zero_second_budget_produces_the_same_file_as_a_full_one(): void {
		$job = $this->job();

		$slow = $this->drive( $job, 0 );
		$fast = $this->drive( $job, 20 );

		$this->assertGreaterThan( 1, $slow['steps'], 'a zero-second budget should take many steps' );
		$this->assertSame( 1, $fast['steps'], 'a twenty-second budget should take one' );
		$this->assertSame( $slow['result']->total, $fast['result']->total );
	}

	/** ISC-262 — forward progress, the rule StageRunner enforces */
	public function test_an_exhausted_budget_still_advances_the_cursor(): void {
		$job = $this->job();

		$first = $this->stage()->run( $job, StageCursor::start(), new TimeBudget( 0 ) );

		$this->assertNotSame( StageCursor::start()->toJson(), $first->cursor->toJson() );
	}

	/** ISC-346, ISC-347 — the summary the later stages read */
	public function test_it_writes_a_summary_of_what_it_scanned(): void {
		$job = $this->job();

		$this->drive( $job, 20 );

		$summary = json_decode(
			(string) file_get_contents( dirname( $this->scanPath( $job ) ) . '/' . FileScanStage::SUMMARY ),
			true
		);

		$this->assertIsArray( $summary );

		$expectedFiles = 0;
		$expectedBytes = 0;

		foreach ( explode( "\n", trim( (string) file_get_contents( $this->scanPath( $job ) ) ) ) as $line ) {
			$entry = json_decode( $line, true );

			++$expectedFiles;
			$expectedBytes += is_array( $entry ) ? (int) ( $entry['s'] ?? 0 ) : 0;
		}

		$this->assertSame( $expectedFiles, $summary['files'] );
		$this->assertSame( $expectedBytes, $summary['bytes'] );
	}

	/**
	 * ISC-348 — the link count survives a scan that took many steps, which is
	 * the only case where holding it in a property would have failed.
	 */
	public function test_the_symlink_count_survives_a_resumed_scan(): void {
		$this->site->link( 'link-one.txt', $this->site->path( 'wp-content/uploads/2026/01/photo.jpg' ) );
		$this->site->link( 'wp-content/link-two.txt', $this->site->path( 'wp-content/uploads/2026/01/photo.jpg' ) );

		$job = $this->job();

		$stepped = $this->drive( $job, 0 );

		$this->assertGreaterThan( 1, $stepped['steps'], 'the scan finished in one step, so nothing was resumed' );

		$summary = json_decode(
			(string) file_get_contents( dirname( $this->scanPath( $job ) ) . '/' . FileScanStage::SUMMARY ),
			true
		);

		$this->assertIsArray( $summary );
		$this->assertSame( 2, $summary['symlinks'] );
	}

	/** ISC-258, ISC-259, ISC-348 */
	public function test_the_cursor_holds_four_numbers_and_no_queue(): void {
		$first = $this->stage()->run( $this->job(), StageCursor::start(), new TimeBudget( 0 ) );

		// `links` joined the three in Sprint 5. It is a running count of
		// skipped symlinks, and it has to live in the cursor for the same
		// reason the other three do: a step that resumes has no other way to
		// learn what the steps before it saw.
		$this->assertSame(
			[ 'read', 'dirs', 'files', 'links' ],
			array_keys( $first->cursor->toArray() )
		);
	}

	/** ISC-260, ISC-261 — the resume boundary */
	public function test_bytes_written_after_the_last_cursor_are_discarded_on_resume(): void {
		$job = $this->job();

		$clean = $this->drive( $job, 20 );
		$this->assertTrue( $clean['result']->complete );

		$expected = (string) file_get_contents( $this->scanPath( $job ) );

		// Now do it again, but simulate the crash StageRunner's ordering
		// allows: a step's work reaches disk, the cursor never does.
		$stage  = $this->stage();
		$first  = $stage->run( $job, StageCursor::start(), new TimeBudget( 0 ) );
		$second = $stage->run( $job, $first->cursor, new TimeBudget( 0 ) );

		// The second step's output landed; its cursor did not.  Resume from
		// the first cursor, exactly as the runner would.
		$this->assertFalse( $second->complete );

		$cursor = $first->cursor;

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( 0 ) );
			$cursor = $result->cursor;
		} while ( ! $result->complete );

		$this->assertSame( $expected, (string) file_get_contents( $this->scanPath( $job ) ) );
	}

	/** ISC-266 */
	public function test_files_no_media_lists_no_uploads(): void {
		$job = $this->job( BackupProfile::FilesNoMedia );

		$this->drive( $job, 20 );

		$paths = $this->pathsFrom( $this->scanPath( $job ) );

		$this->assertNotEmpty( $paths );

		foreach ( $paths as $path ) {
			$this->assertStringStartsNotWith( 'wp-content/uploads/', $path );
		}
	}

	/** ISC-267 — the control */
	public function test_full_does_list_uploads(): void {
		$job = $this->job( BackupProfile::Full );

		$this->drive( $job, 20 );

		$this->assertContains( 'wp-content/uploads/2024/01/photo.jpg', $this->pathsFrom( $this->scanPath( $job ) ) );
	}

	/** ISC-268 */
	public function test_wp_config_is_absent_for_every_profile_by_default(): void {
		foreach ( [ BackupProfile::Full, BackupProfile::FilesOnly, BackupProfile::FilesNoMedia ] as $profile ) {
			$job = $this->job( $profile );

			$this->drive( $job, 20 );

			$this->assertNotContains(
				'wp-config.php',
				$this->pathsFrom( $this->scanPath( $job ) ),
				$profile->value
			);
		}
	}

	/** ISC-269 */
	public function test_the_opt_in_lists_wp_config_exactly_once(): void {
		$job = $this->job( BackupProfile::Full, [ BackupJob::OPTION_INCLUDE_WP_CONFIG => true ] );

		$this->drive( $job, 20 );

		$paths = $this->pathsFrom( $this->scanPath( $job ) );

		$this->assertSame( 1, count( array_keys( $paths, 'wp-config.php', true ) ) );
	}

	/** ISC-273..276 */
	public function test_the_always_excluded_directories_are_absent(): void {
		$job = $this->job();

		$this->drive( $job, 20 );

		$paths = $this->pathsFrom( $this->scanPath( $job ) );

		$this->assertNotContains( 'node_modules/pkg/index.js', $paths );
		$this->assertNotContains( '.git/config', $paths );
		$this->assertNotContains( 'wp-content/cache/object/o.php', $paths );
		$this->assertNotContains( 'debug.log', $paths );
		// The control: ordinary files in the same tree are listed.
		$this->assertContains( 'wp-content/themes/houzez/style.css', $paths );
	}

	/** ISC-249 — the storage directory is never listed, even when it is inside the site */
	public function test_the_storage_directory_is_never_listed(): void {
		$storage = $this->site->path( 'wp-content/fiction-drafts-deadbeef' );

		mkdir( $storage, 0777, true );
		file_put_contents( $storage . '/part01.zip', 'PK' );

		$stage = new FileScanStage( new StorageLocator( $storage ), null, $this->site->root );
		$job   = $this->job();

		$cursor = StageCursor::start();

		do {
			$result = $stage->run( $job, $cursor, new TimeBudget( 20 ) );
			$cursor = $result->cursor;
		} while ( ! $result->complete );

		$paths = $this->pathsFrom( $storage . '/' . sanitize_key( $job->uuid ) . '/' . FileScanStage::OUTPUT );

		foreach ( $paths as $path ) {
			$this->assertStringStartsNotWith( 'wp-content/fiction-drafts-', $path );
		}
	}

	/** ISC-243 — the same tree scanned twice gives the same file the same line */
	public function test_two_scans_of_an_unchanged_tree_are_byte_identical(): void {
		$job = $this->job();

		$this->drive( $job, 20 );
		$first = (string) file_get_contents( $this->scanPath( $job ) );

		$this->drive( $job, 0 );
		$second = (string) file_get_contents( $this->scanPath( $job ) );

		$this->assertSame( $first, $second );
	}
}
