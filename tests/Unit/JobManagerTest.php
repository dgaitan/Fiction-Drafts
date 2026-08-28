<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\JobManager;
use FictionDrafts\Backup\Scheduler;
use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Backup\StageRunner;
use FictionDrafts\Backup\StaleJobWatchdog;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\RecordingScheduler;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobManagerTest extends TestCase {

	private InMemoryJobStore $jobs;

	private RecordingScheduler $scheduler;

	private JobManager $manager;

	private string $storageDir = '';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->jobs       = new InMemoryJobStore();
		$this->scheduler  = new RecordingScheduler();
		$this->storageDir = sys_get_temp_dir() . '/fd-jobman-' . bin2hex( random_bytes( 6 ) );
		$this->manager    = new JobManager( $this->jobs, $this->scheduler, new StorageLocator( $this->storageDir ) );
	}

	protected function tearDown(): void {
		TempTree::removeTree( $this->storageDir );

		parent::tearDown();
	}

	private function naming(): VolumeNaming {
		return new VolumeNaming( $this->storageDir );
	}

	public function testANewJobGetsAUuidAndQueuedStatus(): void {
		$job = $this->manager->create( BackupProfile::Full );

		$this->assertSame( JobStatus::Queued, $job->status );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
			$job->uuid
		);
	}

	public function testCreatingAJobEnqueuesExactlyOneStepCarryingItsUuid(): void {
		$job = $this->manager->create( BackupProfile::Full );

		$this->assertSame( [ $job->uuid ], $this->scheduler->enqueued );
	}

	public function testASecondJobIsRefusedWhileOneIsQueued(): void {
		$this->manager->create( BackupProfile::Full );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( JobManager::REASON_ALREADY_ACTIVE );

		$this->manager->create( BackupProfile::DatabaseOnly );
	}

	public function testASecondJobIsRefusedWhileOneIsRunning(): void {
		$first = $this->manager->create( BackupProfile::Full );
		$this->jobs->save( $first->with( [ 'status' => JobStatus::Running ] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( JobManager::REASON_ALREADY_ACTIVE );

		$this->manager->create( BackupProfile::DatabaseOnly );
	}

	public function testARefusedJobSchedulesNothing(): void {
		$this->manager->create( BackupProfile::Full );
		$this->scheduler->enqueued = [];

		try {
			$this->manager->create( BackupProfile::DatabaseOnly );
		} catch ( RuntimeException ) {
			$this->assertSame( [], $this->scheduler->enqueued );

			return;
		}

		$this->fail( 'the second create should have been refused' );
	}

	public function testAJobIsAllowedAgainOnceTheFirstIsTerminal(): void {
		$first = $this->manager->create( BackupProfile::Full );
		$this->jobs->save( $first->with( [ 'status' => JobStatus::Completed ] ) );

		$second = $this->manager->create( BackupProfile::DatabaseOnly );

		$this->assertSame( JobStatus::Queued, $second->status );
	}

	// -----------------------------------------------------------------
	// The zero-content guard (Sprint 1's ISC-60, discharged here).
	// -----------------------------------------------------------------

	public function testACustomJobSelectingNothingIsRefused(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( JobManager::REASON_NOTHING_SELECTED );

		$this->manager->create( BackupProfile::Custom );
	}

	public function testACustomJobSelectingNothingSchedulesNothing(): void {
		try {
			$this->manager->create( BackupProfile::Custom );
		} catch ( RuntimeException ) {
			$this->assertSame( [], $this->scheduler->enqueued );

			return;
		}

		$this->fail( 'an empty custom job should have been refused' );
	}

	public function testACustomJobWithOneAreaSelectedIsAccepted(): void {
		$job = $this->manager->create(
			BackupProfile::Custom,
			[ BackupJob::OPTION_INCLUDE_DATABASE => true ]
		);

		$this->assertSame( JobStatus::Queued, $job->status );
	}

	/**
	 * @return array<string, array{0: BackupProfile}>
	 */
	public static function presetProfiles(): array {
		return [
			'full'           => [ BackupProfile::Full ],
			'database only'  => [ BackupProfile::DatabaseOnly ],
			'files only'     => [ BackupProfile::FilesOnly ],
			'files no media' => [ BackupProfile::FilesNoMedia ],
		];
	}

	/**
	 * @dataProvider presetProfiles
	 */
	public function testEveryPresetProfileSelectsSomething( BackupProfile $profile ): void {
		$job = new BackupJob( uuid: 'x', profile: $profile );

		$this->assertTrue( $job->selectsAnyContent() );
	}

	public function testABareCustomJobSelectsNothing(): void {
		$job = new BackupJob( uuid: 'x', profile: BackupProfile::Custom );

		$this->assertFalse( $job->selectsAnyContent() );
	}

	public function testCustomAreaOptionsOverrideTheProfilePredicates(): void {
		$job = new BackupJob(
			uuid: 'x',
			profile: BackupProfile::Custom,
			options: [ BackupJob::OPTION_INCLUDE_UPLOADS => true ]
		);

		$this->assertTrue( $job->includesUploads() );
		$this->assertFalse( $job->includesDatabase() );
		$this->assertFalse( $job->includesCore() );
	}

	public function testAreaOptionsAreIgnoredForPresetProfiles(): void {
		$job = new BackupJob(
			uuid: 'x',
			profile: BackupProfile::DatabaseOnly,
			options: [ BackupJob::OPTION_INCLUDE_UPLOADS => true ]
		);

		$this->assertFalse( $job->includesUploads(), 'a preset profile answers for itself' );
	}

	// -----------------------------------------------------------------
	// Cancel.
	// -----------------------------------------------------------------

	public function testCancellingSetsTheStatusAndUnschedules(): void {
		$job = $this->manager->create( BackupProfile::Full );

		$cancelled = $this->manager->cancel( $job->uuid );

		$this->assertInstanceOf( BackupJob::class, $cancelled );
		$this->assertSame( JobStatus::Cancelled, $cancelled->status );
		$this->assertContains( $job->uuid, $this->scheduler->unscheduled );
	}

	public function testCancellingATerminalJobLeavesItAlone(): void {
		$job = $this->manager->create( BackupProfile::Full );
		$this->jobs->save( $job->with( [ 'status' => JobStatus::Completed ] ) );

		$result = $this->manager->cancel( $job->uuid );

		$this->assertInstanceOf( BackupJob::class, $result );
		$this->assertSame( JobStatus::Completed, $result->status );
	}

	public function testCancellingAnUnknownJobReturnsNull(): void {
		$this->assertNull( $this->manager->cancel( 'no-such-job' ) );
	}

	/** ISC-370 */
	public function testCancellingRemovesTheWorkingDirectory(): void {
		$job     = $this->manager->create( BackupProfile::Full );
		$working = $this->storageDir . '/' . sanitize_key( $job->uuid );

		mkdir( $working, 0777, true );
		file_put_contents( $working . '/database.sql', 'CREATE TABLE x;' );

		$this->manager->cancel( $job->uuid );

		$this->assertDirectoryDoesNotExist( $working );
	}

	/**
	 * ISC-372 — a cancelled job's volumes are an archive missing whatever the
	 * run had not reached. Left on disk they are indistinguishable from a
	 * finished backup to a directory listing, to the retention sweep, and to
	 * whoever downloads one.
	 */
	public function testCancellingRemovesThePartialVolumes(): void {
		$job = $this->manager->create( BackupProfile::Full );

		file_put_contents( $this->naming()->pathFor( $job, 1 ), 'partial' );
		file_put_contents( $this->naming()->pathFor( $job, 2 ), 'partial' );

		$this->manager->cancel( $job->uuid );

		$this->assertSame( [], $this->naming()->sequencesFor( $job ) );
	}

	/** ISC-372 — the control: another job's volumes are untouched */
	public function testCancellingLeavesAnotherJobsVolumesAlone(): void {
		$job = $this->manager->create( BackupProfile::Full );

		$other = new BackupJob( 'ffffffff-1111-2222-3333-444444444444', BackupProfile::Full, createdAt: $job->createdAt );

		file_put_contents( $this->naming()->pathFor( $job, 1 ), 'mine' );
		file_put_contents( $this->naming()->pathFor( $other, 1 ), 'theirs' );

		$this->manager->cancel( $job->uuid );

		$this->assertSame( [ 1 ], $this->naming()->sequencesFor( $other ) );
	}

	/** ISC-371 — a finished job keeps its volumes; cancel is a no-op */
	public function testCancellingAFinishedJobKeepsItsVolumes(): void {
		$job = $this->manager->create( BackupProfile::Full );
		$this->jobs->save( $job->with( [ 'status' => JobStatus::Completed ] ) );

		file_put_contents( $this->naming()->pathFor( $job, 1 ), 'finished' );

		$this->manager->cancel( $job->uuid );

		$this->assertSame( [ 1 ], $this->naming()->sequencesFor( $job ) );
	}

	// -----------------------------------------------------------------
	// Scheduler contract.
	// -----------------------------------------------------------------

	public function testTheActionGroupIsDedicatedToThisPlugin(): void {
		$this->assertSame( 'fiction-drafts', Scheduler::GROUP );
	}

	public function testTheStepHookIsNamespaced(): void {
		$this->assertSame( 'fiction_drafts/run_step', Scheduler::HOOK_RUN_STEP );
	}

	public function testTheSchedulerDegradesSafelyWithoutActionScheduler(): void {
		$scheduler = new Scheduler();

		// The as_* functions do not exist under unit tests, so every call must
		// be a safe no-op rather than a fatal.
		$this->assertFalse( $scheduler->isAvailable() );
		$this->assertSame( 0, $scheduler->enqueueStep( 'x' ) );
		$this->assertFalse( $scheduler->hasPendingStep( 'x' ) );

		$scheduler->scheduleRecurring();
		$scheduler->unscheduleJob( 'x' );
		$scheduler->unscheduleAll();

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------
	// Watchdog.
	// -----------------------------------------------------------------

	public function testTheWatchdogFailsAJobUntouchedForSixteenMinutes(): void {
		$runner   = new StageRunner( $this->jobs, new StageRegistry(), $this->scheduler );
		$watchdog = new StaleJobWatchdog( $this->jobs, $runner );

		$this->jobs->insert(
			new BackupJob(
				uuid: 'stale',
				profile: BackupProfile::Full,
				status: JobStatus::Running,
				updatedAt: gmdate( 'Y-m-d H:i:s', time() - ( 16 * 60 ) )
			)
		);

		$watchdog->sweep();

		$job = $this->jobs->findByUuid( 'stale' );
		$this->assertInstanceOf( BackupJob::class, $job );
		$this->assertSame( JobStatus::Failed, $job->status );
		$this->assertNotNull( $job->error );
	}

	public function testTheWatchdogLeavesARecentlyUpdatedJobAlone(): void {
		$runner   = new StageRunner( $this->jobs, new StageRegistry(), $this->scheduler );
		$watchdog = new StaleJobWatchdog( $this->jobs, $runner );

		$this->jobs->insert(
			new BackupJob(
				uuid: 'fresh',
				profile: BackupProfile::Full,
				status: JobStatus::Running,
				updatedAt: gmdate( 'Y-m-d H:i:s', time() - ( 5 * 60 ) )
			)
		);

		$watchdog->sweep();

		$job = $this->jobs->findByUuid( 'fresh' );
		$this->assertInstanceOf( BackupJob::class, $job );
		$this->assertSame( JobStatus::Running, $job->status );
	}

	public function testTheWatchdogNeverTouchesATerminalJob(): void {
		$runner   = new StageRunner( $this->jobs, new StageRegistry(), $this->scheduler );
		$watchdog = new StaleJobWatchdog( $this->jobs, $runner );

		$this->jobs->insert(
			new BackupJob(
				uuid: 'old-but-done',
				profile: BackupProfile::Full,
				status: JobStatus::Completed,
				updatedAt: gmdate( 'Y-m-d H:i:s', time() - ( 600 * 60 ) )
			)
		);

		$watchdog->sweep();

		$job = $this->jobs->findByUuid( 'old-but-done' );
		$this->assertInstanceOf( BackupJob::class, $job );
		$this->assertSame( JobStatus::Completed, $job->status );
	}
}
