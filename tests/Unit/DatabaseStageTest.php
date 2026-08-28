<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\Stages\DatabaseStage;
use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Backup\StageRunner;
use FictionDrafts\Database\SqlDumper;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\FakeDatabase;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\RecordingScheduler;
use PHPUnit\Framework\TestCase;

/**
 * The stage's resume behaviour, proved the same way Sprint 2 proved the runner:
 * drive the same work at a zero-second budget and at twenty, and require the
 * two outputs to be byte-identical.
 *
 * The extra thing this file proves, which nothing in Sprint 2 could, is that
 * bytes written *after* the last persisted cursor are discarded on resume.  A
 * stage that appends to a file cannot rely on "a repeated batch is harmless"
 * unless the repeat overwrites rather than appends.
 */
final class DatabaseStageTest extends TestCase {

	private string $storageDir = '';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->storageDir = sys_get_temp_dir() . '/fd-dbstage-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->storageDir, 0777, true );
	}

	protected function tearDown(): void {
		self::removeTree( $this->storageDir );

		parent::tearDown();
	}

	private static function removeTree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$child = $path . '/' . $entry;

			if ( is_dir( $child ) ) {
				self::removeTree( $child );

				continue;
			}

			unlink( $child );
		}

		rmdir( $path );
	}

	/**
	 * A locator rooted in this test's own temp directory.
	 */
	private function storage(): StorageLocator {
		return new StorageLocator( $this->storageDir );
	}

	/**
	 * A fake database holding one table with $rows numbered rows.
	 */
	private function database( int $rows, string $table = 'wp_things' ): FakeDatabase {
		$db = new FakeDatabase();

		$data = [];

		for ( $i = 1; $i <= $rows; $i++ ) {
			$data[] = [
				'id'    => (string) $i,
				'label' => 'row-' . $i,
			];
		}

		$db->addTable(
			$table,
			[
				[
					'Field' => 'id',
					'Type'  => 'bigint',
					'Key'   => 'PRI',
					'Extra' => '',
				],
				[
					'Field' => 'label',
					'Type'  => 'varchar(50)',
					'Key'   => '',
					'Extra' => '',
				],
			],
			$data
		);

		return $db;
	}

	private function job( BackupProfile $profile = BackupProfile::DatabaseOnly, string $uuid = 'db-stage-job' ): BackupJob {
		return new BackupJob(
			uuid: $uuid,
			profile: $profile,
			status: JobStatus::Queued,
			createdAt: '2026-08-28 00:00:00',
			updatedAt: '2026-08-28 00:00:00'
		);
	}

	/**
	 * Drive one job to completion, one step per recorded enqueue.
	 *
	 * @return array{job: BackupJob, steps: int, path: string}
	 */
	private function drive( FakeDatabase $db, int $budget, int $batch = 10, string $uuid = 'db-stage-job' ): array {
		fiction_drafts_test_reset_hooks();

		$storage = $this->storage();
		$stage   = new DatabaseStage( $db, $storage );

		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static fn ( array $stages ): array => [ ...$stages, $stage ]
		);
		fiction_drafts_test_add_filter( 'fiction_drafts/time_budget_seconds', static fn (): int => $budget );
		fiction_drafts_test_add_filter( SqlDumper::FILTER_BATCH_ROWS, static fn (): int => $batch );

		$jobs      = new InMemoryJobStore();
		$scheduler = new RecordingScheduler();
		$runner    = new StageRunner( $jobs, new StageRegistry(), $scheduler );

		$job = $jobs->insert( $this->job( BackupProfile::DatabaseOnly, $uuid ) );

		$steps   = 0;
		$pending = 1;

		while ( $pending > 0 && $steps < 5000 ) {
			$scheduler->enqueued = [];
			$runner->step( $job->uuid );
			++$steps;
			$pending = count( $scheduler->enqueued );
		}

		$final = $jobs->findByUuid( $job->uuid );
		$this->assertInstanceOf( BackupJob::class, $final );

		return [
			'job'   => $final,
			'steps' => $steps,
			'path'  => $storage->workingDir( $uuid ) . '/database.sql',
		];
	}

	// -----------------------------------------------------------------
	// appliesTo — spec §6.1, one profile per assertion.
	// -----------------------------------------------------------------

	public function testItAppliesToEveryProfileThatIncludesTheDatabase(): void {
		$stage = new DatabaseStage( $this->database( 0 ), $this->storage() );

		$this->assertTrue( $stage->appliesTo( $this->job( BackupProfile::Full ) ) );
		$this->assertTrue( $stage->appliesTo( $this->job( BackupProfile::DatabaseOnly ) ) );
		$this->assertTrue( $stage->appliesTo( $this->job( BackupProfile::FilesNoMedia ) ) );
		$this->assertFalse( $stage->appliesTo( $this->job( BackupProfile::FilesOnly ) ) );
	}

	public function testACustomJobDefersToItsOwnOption(): void {
		$stage = new DatabaseStage( $this->database( 0 ), $this->storage() );
		$job   = $this->job( BackupProfile::Custom );

		$this->assertFalse( $stage->appliesTo( $job ) );
		$this->assertTrue(
			$stage->appliesTo( $job->with( [ 'options' => [ BackupJob::OPTION_INCLUDE_DATABASE => true ] ] ) )
		);
	}

	public function testItIdentifiesItselfForThePipelineAndTheProgressBar(): void {
		$stage = new DatabaseStage( $this->database( 0 ), $this->storage() );

		$this->assertSame( 'database', $stage->id() );
		$this->assertNotSame( '', $stage->label() );
	}

	// -----------------------------------------------------------------
	// The proof.
	// -----------------------------------------------------------------

	public function testAZeroSecondBudgetStillCompletesAndMatchesASingleStep(): void {
		$slow = $this->drive( $this->database( 500 ), 0, 10, 'slow-job' );
		$fast = $this->drive( $this->database( 500 ), 20, 10, 'fast-job' );

		$this->assertSame( JobStatus::Completed, $slow['job']->status );
		$this->assertSame( JobStatus::Completed, $fast['job']->status );

		$this->assertGreaterThanOrEqual( 10, $slow['steps'] );
		$this->assertSame( 1, $fast['steps'] );

		$this->assertSame(
			file_get_contents( $fast['path'] ),
			file_get_contents( $slow['path'] ),
			'A dump written across fifty steps must be byte-identical to one written in a single step.'
		);
	}

	public function testEveryRowAppearsExactlyOnceAndInOrder(): void {
		$result = $this->drive( $this->database( 250 ), 0, 10 );
		$sql    = (string) file_get_contents( $result['path'] );

		for ( $i = 1; $i <= 250; $i++ ) {
			$this->assertSame(
				1,
				substr_count( $sql, "'row-" . $i . "'" ),
				'Row ' . $i . ' must appear exactly once.'
			);
		}

		$this->assertLessThan(
			strpos( $sql, "'row-250'" ),
			(int) strpos( $sql, "'row-1'" )
		);
	}

	public function testTheDumpIsWellFormedFromHeaderToFooter(): void {
		$sql = (string) file_get_contents( $this->drive( $this->database( 30 ), 0, 10 )['path'] );

		$this->assertStringContainsString( 'SET NAMES utf8mb4;', $sql );
		$this->assertStringContainsString( 'SET FOREIGN_KEY_CHECKS=0;', $sql );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_things`;', $sql );
		$this->assertStringContainsString( 'CREATE TABLE `wp_things`', $sql );
		$this->assertStringContainsString( 'INSERT INTO `wp_things` (`id`,`label`)', $sql );
		$this->assertStringEndsWith( "SET FOREIGN_KEY_CHECKS=1;\n", $sql );
	}

	public function testAnEmptyDatabaseStillProducesAValidFile(): void {
		$result = $this->drive( new FakeDatabase(), 0 );
		$sql    = (string) file_get_contents( $result['path'] );

		$this->assertSame( JobStatus::Completed, $result['job']->status );
		$this->assertStringContainsString( 'SET NAMES utf8mb4;', $sql );
		$this->assertStringEndsWith( "SET FOREIGN_KEY_CHECKS=1;\n", $sql );
		$this->assertStringNotContainsString( 'INSERT INTO', $sql );
	}

	// -----------------------------------------------------------------
	// The byte boundary — what nothing in Sprint 2 could catch.
	// -----------------------------------------------------------------

	public function testAResumeDiscardsBytesTheCursorDoesNotAccountFor(): void {
		$db = $this->database( 100 );
		fiction_drafts_test_reset_hooks();

		$storage = $this->storage();
		$stage   = new DatabaseStage( $db, $storage );

		fiction_drafts_test_add_filter( SqlDumper::FILTER_BATCH_ROWS, static fn (): int => 10 );

		$path = $storage->workingDir( 'kill-job' ) . '/database.sql';
		$job  = $this->job( BackupProfile::DatabaseOnly, 'kill-job' );

		// One bounded step, exactly as the runner would run it.
		$first = $stage->run( $job, $job->cursor(), new TimeBudget( 0 ) );

		$this->assertFalse( $first->complete );

		$persisted = (string) file_get_contents( $path );

		// Now simulate the crash the cursor cannot see: a partial write that
		// landed on disk after the last persisted position.
		file_put_contents( $path, "INSERT INTO `wp_things` (`id`,`label`) VALUES (99,'tor", FILE_APPEND );

		$this->assertNotSame( $persisted, file_get_contents( $path ) );

		$second = $stage->run( $job->with( [ 'cursor' => $first->cursor ] ), $first->cursor, new TimeBudget( 0 ) );

		$resumed = (string) file_get_contents( $path );

		$this->assertStringStartsWith( $persisted, $resumed, 'The persisted prefix must survive untouched.' );
		$this->assertStringNotContainsString( "(99,'tor", $resumed, 'The unaccounted-for tail must be discarded.' );
		$this->assertFalse( $second->complete );
	}

	public function testTheCursorCarriesTheOutputByteLength(): void {
		$db = $this->database( 100 );
		fiction_drafts_test_reset_hooks();

		$storage = $this->storage();
		$stage   = new DatabaseStage( $db, $storage );

		fiction_drafts_test_add_filter( SqlDumper::FILTER_BATCH_ROWS, static fn (): int => 10 );

		$job    = $this->job( BackupProfile::DatabaseOnly, 'bytes-job' );
		$result = $stage->run( $job, $job->cursor(), new TimeBudget( 0 ) );

		$path = $storage->workingDir( 'bytes-job' ) . '/database.sql';

		$this->assertSame( filesize( $path ), $result->cursor->getInt( 'bytes' ) );
		$this->assertSame( 0, $result->cursor->getInt( 'index' ) );
		$this->assertSame( 1, $result->cursor->getInt( 'schema' ) );
	}

	public function testEveryStepAdvancesTheCursorSoTheRunnerNeverStalls(): void {
		$result = $this->drive( $this->database( 100 ), 0, 10 );

		$this->assertSame( JobStatus::Completed, $result['job']->status );
		$this->assertNull( $result['job']->error );
	}

	// -----------------------------------------------------------------
	// The table list is resolved once.
	// -----------------------------------------------------------------

	public function testTheTableListIsPersistedAndReusedAcrossSteps(): void {
		$db = $this->database( 100 );
		fiction_drafts_test_reset_hooks();

		$storage = $this->storage();
		$stage   = new DatabaseStage( $db, $storage );

		fiction_drafts_test_add_filter( SqlDumper::FILTER_BATCH_ROWS, static fn (): int => 10 );

		$job   = $this->job( BackupProfile::DatabaseOnly, 'list-job' );
		$first = $stage->run( $job, $job->cursor(), new TimeBudget( 0 ) );

		$listPath = $storage->workingDir( 'list-job' ) . '/tables.json';

		$this->assertFileExists( $listPath );
		$this->assertSame( [ 'wp_things' ], json_decode( (string) file_get_contents( $listPath ), true ) );

		// A table created mid-backup must not shift the cursor's index.
		$db->addTable(
			'wp_aaa_new',
			[
				[
					'Field' => 'id',
					'Type'  => 'bigint',
					'Key'   => 'PRI',
					'Extra' => '',
				],
			]
		);

		$stage->run( $job->with( [ 'cursor' => $first->cursor ] ), $first->cursor, new TimeBudget( 0 ) );

		$this->assertSame( [ 'wp_things' ], json_decode( (string) file_get_contents( $listPath ), true ) );
	}

	public function testItReadsInUtcAndPutsTheSessionTimeZoneBack(): void {
		$db      = $this->database( 20 );
		$storage = $this->storage();
		$stage   = new DatabaseStage( $db, $storage );

		fiction_drafts_test_add_filter( SqlDumper::FILTER_BATCH_ROWS, static fn (): int => 5 );

		$job = $this->job( BackupProfile::DatabaseOnly, 'tz-job' );
		$stage->run( $job, $job->cursor(), new TimeBudget( 0 ) );

		// A `timestamp` column is converted to and from the session time zone
		// on every read, so a dump read in one zone and imported in another
		// shifts every one of them — silently.
		$this->assertSame( '+00:00', $db->timeZones[0] );
		$this->assertSame( 'SYSTEM', $db->timeZones[1], 'The session zone must be restored for the rest of the request.' );
		$this->assertSame( 'SYSTEM', $db->timeZone() );
	}

	public function testTheSessionTimeZoneIsRestoredEvenWhenTheStageThrows(): void {
		$db    = $this->database( 5 );
		$stage = new DatabaseStage( $db, new StorageLocator( '/fiction-drafts/no/such/path' ) );
		$job   = $this->job( BackupProfile::DatabaseOnly, 'tz-throw' );

		try {
			$stage->run( $job, $job->cursor(), new TimeBudget( 0 ) );
			$this->fail( 'The stage should not have been able to create its working directory.' );
		} catch ( \Throwable ) {
			$this->assertSame( 'SYSTEM', $db->timeZone() );
		}
	}

	public function testTheStageReportsAnEstimatedTotalForTheProgressBar(): void {
		$result = $this->drive( $this->database( 40 ), 0, 10 );

		$this->assertSame( 40, $result['job']->total );
		$this->assertSame( 40, $result['job']->processed );
		$this->assertSame( 100, $result['job']->percent() );
	}
}
