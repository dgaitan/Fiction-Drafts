<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\JobLock;
use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Backup\StageRunner;
use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Tests\Support\CountingStage;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\RecordingScheduler;
use FictionDrafts\Tests\Support\WritingStage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The resumability proof, and the runner's contract around it.
 *
 * Sprints 3 to 5 are written against the guarantee this file establishes, so
 * the sequencing constraint in the plan is real: nothing downstream should be
 * built until the zero-second-budget test passes.
 */
final class StageRunnerTest extends TestCase {

	private string $output = '';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();

		$this->output = (string) tempnam( sys_get_temp_dir(), 'fd-proof-' );
		file_put_contents( $this->output, '' );
	}

	protected function tearDown(): void {
		if ( '' !== $this->output && file_exists( $this->output ) ) {
			unlink( $this->output );
		}

		parent::tearDown();
	}

	/**
	 * Drive a job to completion, one step per enqueue, and report the count.
	 *
	 * This is what a real Action Scheduler queue does, minus the timing: the
	 * runner enqueues, the queue calls back, repeat. Driving it here makes the
	 * step count observable and the whole run deterministic.
	 *
	 * @param  array<int, Stage> $stages  Pipeline to register.
	 * @param  int               $budget  Seconds per step.
	 * @return array{job: BackupJob, steps: int}
	 */
	private function drive( array $stages, int $budget, int $maxSteps = 5000 ): array {
		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static fn ( array $registered ): array => array_merge( $registered, $stages )
		);

		fiction_drafts_test_add_filter(
			'fiction_drafts/time_budget_seconds',
			static fn (): int => $budget
		);

		$jobs      = new InMemoryJobStore();
		$scheduler = new RecordingScheduler();
		$runner    = new StageRunner( $jobs, new StageRegistry(), $scheduler );

		$job = $jobs->insert(
			new BackupJob(
				uuid: 'proof-job',
				profile: BackupProfile::Full,
				status: JobStatus::Queued,
				createdAt: '2026-08-28 00:00:00',
				updatedAt: '2026-08-28 00:00:00'
			)
		);

		$steps   = 0;
		$pending = 1;

		while ( $pending > 0 && $steps < $maxSteps ) {
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
		];
	}

	/**
	 * @return array<int, int>
	 */
	private function unitsWritten(): array {
		$lines = array_filter( explode( "\n", (string) file_get_contents( $this->output ) ), 'strlen' );

		return array_map( 'intval', array_values( $lines ) );
	}

	// -----------------------------------------------------------------
	// The proof.
	// -----------------------------------------------------------------

	public function testAZeroSecondBudgetStillDrivesAThousandUnitsToCompletion(): void {
		$result = $this->drive( [ new CountingStage( 1000, $this->output ) ], 0 );

		$this->assertSame( JobStatus::Completed, $result['job']->status );
	}

	public function testAZeroSecondBudgetTakesTenOrMoreSteps(): void {
		$result = $this->drive( [ new CountingStage( 1000, $this->output ) ], 0 );

		$this->assertGreaterThanOrEqual( 10, $result['steps'] );
	}

	public function testEveryUnitIsWrittenExactlyOnce(): void {
		$this->drive( [ new CountingStage( 1000, $this->output ) ], 0 );

		$units = $this->unitsWritten();

		$this->assertCount( 1000, $units, 'one line per unit, no more and no fewer' );
		$this->assertSame( 1000, count( array_unique( $units ) ), 'no unit written twice' );
	}

	public function testTheUnitsAppearInOrder(): void {
		$this->drive( [ new CountingStage( 1000, $this->output ) ], 0 );

		$units  = $this->unitsWritten();
		$sorted = $units;
		sort( $sorted );

		$this->assertSame( $sorted, $units, 'a resume must not reorder work' );
		$this->assertSame( range( 0, 999 ), $units );
	}

	public function testAGenerousBudgetCompletesInASingleStep(): void {
		$result = $this->drive( [ new CountingStage( 1000, $this->output ) ], 20 );

		$this->assertSame( JobStatus::Completed, $result['job']->status );
		$this->assertSame( 1, $result['steps'] );
	}

	/**
	 * The claim that makes the whole design worth having: how many times a job
	 * was interrupted changes nothing about what it produced.
	 */
	public function testInterruptedAndUninterruptedRunsProduceByteIdenticalOutput(): void {
		$this->drive( [ new CountingStage( 1000, $this->output ) ], 0 );
		$interrupted = (string) file_get_contents( $this->output );

		file_put_contents( $this->output, '' );
		fiction_drafts_test_reset_hooks();

		$this->drive( [ new CountingStage( 1000, $this->output ) ], 20 );
		$uninterrupted = (string) file_get_contents( $this->output );

		$this->assertSame( $uninterrupted, $interrupted );
		$this->assertSame( hash( 'sha256', $uninterrupted ), hash( 'sha256', $interrupted ) );
	}

	// -----------------------------------------------------------------
	// The forward-progress rule.
	// -----------------------------------------------------------------

	public function testAStageThatMakesNoProgressFailsTheJobInsteadOfLooping(): void {
		$stuck = new class() implements Stage {
			public function id(): string {
				return 'stuck';
			}

			public function label(): string {
				return 'Stuck';
			}

			public function appliesTo( BackupJob $job ): bool {
				return true;
			}

			public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
				return StageResult::incomplete( $cursor, 0 );
			}
		};

		$result = $this->drive( [ $stuck ], 0 );

		$this->assertSame( JobStatus::Failed, $result['job']->status );
		$this->assertSame( 1, $result['steps'], 'the runner must stop after the first no-progress step' );
	}

	/**
	 * A stage with legitimately nothing to do must complete, not be failed by
	 * the forward-progress guard.
	 *
	 * "No work to do" and "unable to make progress" look identical from
	 * outside if a stage returns `incomplete` when its input is empty. The
	 * do-while checks its terminal condition before doing anything, so an
	 * empty stage returns `complete` on its first call — and this test is what
	 * keeps that true. Sprints 3 to 5 will each have an empty case: a site
	 * with no uploads, a table with no rows.
	 */
	public function testAStageWithNothingToDoCompletesRatherThanFailing(): void {
		$result = $this->drive( [ new CountingStage( 0, $this->output ) ], 0 );

		$this->assertSame( JobStatus::Completed, $result['job']->status );
		$this->assertNull( $result['job']->error );
		$this->assertSame( [], $this->unitsWritten() );
	}

	/**
	 * The guard tests the cursor alone, not the cursor and a zero count.
	 *
	 * A stage that reports progress while leaving the resume position
	 * untouched is worse than one that reports none: it loops, and repeats
	 * whatever it claims to have done on every pass.
	 */
	public function testAStageThatReportsProgressButNeverMovesTheCursorAlsoFails(): void {
		$liar = new class() implements Stage {
			public function id(): string {
				return 'busy-but-stuck';
			}

			public function label(): string {
				return 'Busy but stuck';
			}

			public function appliesTo( BackupJob $job ): bool {
				return true;
			}

			public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
				// Claims 50 units of work, resumes from exactly where it started.
				return StageResult::incomplete( $cursor, 50 );
			}
		};

		$result = $this->drive( [ $liar ], 0 );

		$this->assertSame( JobStatus::Failed, $result['job']->status );
		$this->assertSame( 1, $result['steps'] );
		$this->assertStringContainsString( 'busy-but-stuck', (string) $result['job']->error );
	}

	public function testTheNoProgressFailureNamesTheStage(): void {
		$stuck = new class() implements Stage {
			public function id(): string {
				return 'the-stuck-one';
			}

			public function label(): string {
				return 'Stuck';
			}

			public function appliesTo( BackupJob $job ): bool {
				return true;
			}

			public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
				return StageResult::incomplete( $cursor, 0 );
			}
		};

		$result = $this->drive( [ $stuck ], 0 );

		$this->assertStringContainsString( 'the-stuck-one', (string) $result['job']->error );
	}

	// -----------------------------------------------------------------
	// Pipeline behaviour.
	// -----------------------------------------------------------------

	public function testItAdvancesThroughEveryStageInOrder(): void {
		$first  = new CountingStage( 10, $this->output, 'first' );
		$second = new CountingStage( 10, $this->output, 'second' );

		$result = $this->drive( [ $first, $second ], 20 );

		$this->assertSame( JobStatus::Completed, $result['job']->status );
		$this->assertSame( 1, $first->runs );
		$this->assertSame( 1, $second->runs );
		$this->assertCount( 20, $this->unitsWritten() );
	}

	public function testProcessedResetsWhenTheStageAdvances(): void {
		$result = $this->drive(
			[ new CountingStage( 10, $this->output, 'first' ), new CountingStage( 3, $this->output, 'second' ) ],
			20
		);

		// The last stage did 3 units; the first stage's 10 must not still be
		// counted, because the two stages measure different things.
		$this->assertSame( 3, $result['job']->processed );
	}

	public function testAStageThatDoesNotApplyIsSkippedEntirely(): void {
		$skipped = new CountingStage( 10, $this->output, 'skipped', false );
		$running = new CountingStage( 5, $this->output, 'running' );

		$this->drive( [ $skipped, $running ], 20 );

		$this->assertSame( 0, $skipped->runs );
		$this->assertSame( 1, $running->runs );
		$this->assertCount( 5, $this->unitsWritten() );
	}

	public function testAnEmptyPipelineCompletesTheJob(): void {
		$result = $this->drive( [], 20 );

		$this->assertSame( JobStatus::Completed, $result['job']->status );
	}

	public function testCompletedAtIsSetWhenTheJobCompletes(): void {
		$result = $this->drive( [ new CountingStage( 5, $this->output ) ], 20 );

		$this->assertNotNull( $result['job']->completedAt );
	}

	public function testTheCursorIsClearedWhenTheJobCompletes(): void {
		$result = $this->drive( [ new CountingStage( 5, $this->output ) ], 20 );

		$this->assertTrue( $result['job']->cursor()->isStart() );
	}

	// -----------------------------------------------------------------
	// Failure paths.
	// -----------------------------------------------------------------

	public function testAThrowingStageFailsTheJobWithItsMessage(): void {
		$explosive = new class() implements Stage {
			public function id(): string {
				return 'explosive';
			}

			public function label(): string {
				return 'Explosive';
			}

			public function appliesTo( BackupJob $job ): bool {
				return true;
			}

			public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
				throw new RuntimeException( 'the disk went away' );
			}
		};

		$result = $this->drive( [ $explosive ], 20 );

		$this->assertSame( JobStatus::Failed, $result['job']->status );
		$this->assertSame( 'the disk went away', $result['job']->error );
	}

	public function testAThrowingStageNeverLeavesTheJobRunning(): void {
		$explosive = new class() implements Stage {
			public function id(): string {
				return 'explosive';
			}

			public function label(): string {
				return 'Explosive';
			}

			public function appliesTo( BackupJob $job ): bool {
				return true;
			}

			public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
				throw new RuntimeException( 'boom' );
			}
		};

		$result = $this->drive( [ $explosive ], 20 );

		$this->assertTrue( $result['job']->status->isTerminal() );
	}

	public function testAnUnknownUuidIsNotAnError(): void {
		$runner = new StageRunner( new InMemoryJobStore(), new StageRegistry(), new RecordingScheduler() );

		$runner->step( 'no-such-job' );

		$this->addToAssertionCount( 1 );
	}

	public function testATerminalJobIsLeftAlone(): void {
		$jobs      = new InMemoryJobStore();
		$scheduler = new RecordingScheduler();
		$stage     = new CountingStage( 10, $this->output );

		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static fn ( array $registered ): array => array_merge( $registered, [ $stage ] )
		);

		$jobs->insert(
			new BackupJob(
				uuid: 'done-job',
				profile: BackupProfile::Full,
				status: JobStatus::Completed
			)
		);

		( new StageRunner( $jobs, new StageRegistry(), $scheduler ) )->step( 'done-job' );

		$this->assertSame( 0, $stage->runs );
		$this->assertSame( [], $scheduler->enqueued );
	}

	public function testStagesComeFromTheFilterRatherThanAHardCodedList(): void {
		$registry = new StageRegistry();

		$this->assertSame( [], $registry->all(), 'no stage is registered until the filter provides one' );

		$stage = new CountingStage( 1, $this->output, 'filtered' );

		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static fn ( array $registered ): array => array_merge( $registered, [ $stage ] )
		);

		$this->assertCount( 1, $registry->all() );
		$this->assertSame( 'filtered', $registry->all()[0]->id() );
	}

	/**
	 * ISC-330 — a second worker inside one job does nothing at all.
	 *
	 * Through Sprint 3 an overlapping step was merely wasteful: a repeated
	 * batch overwrote itself.  From Sprint 4 it is destructive, because
	 * resuming an archive mutates it — both workers truncate to the same entry
	 * count and both add again.
	 */
	public function test_a_step_that_cannot_take_the_lock_does_nothing(): void {
		$store = new InMemoryJobStore();
		$job   = new BackupJob( 'locked-uuid', BackupProfile::Full );

		$store->save( $job );

		$output  = sys_get_temp_dir() . '/fd-lock-' . bin2hex( random_bytes( 5 ) ) . '.txt';
		$stage   = new CountingStage( 5, $output );
		$cleanup = static function () use ( $output ): void {
			if ( is_file( $output ) ) {
				unlink( $output );
			}
		};

		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static fn ( array $stages ): array => [ ...$stages, $stage ]
		);

		$runner = new StageRunner(
			$store,
			new StageRegistry(),
			new RecordingScheduler(),
			new class() implements JobLock {
				public function acquire(): bool {
					return false;
				}

				public function release(): void {}
			}
		);

		$runner->step( 'locked-uuid' );

		$after = $store->findByUuid( 'locked-uuid' );

		$cleanup();

		$this->assertNotNull( $after );
		$this->assertSame( JobStatus::Queued, $after->status, 'the job should not have been started' );
		$this->assertNull( $after->stage );
	}

	/**
	 * The control: the same runner with a lock it can take does run the step.
	 */
	public function test_a_step_that_takes_the_lock_runs(): void {
		$store = new InMemoryJobStore();
		$job   = new BackupJob( 'free-uuid', BackupProfile::Full );

		$store->save( $job );

		$output  = sys_get_temp_dir() . '/fd-lock-' . bin2hex( random_bytes( 5 ) ) . '.txt';
		$stage   = new CountingStage( 5, $output );
		$cleanup = static function () use ( $output ): void {
			if ( is_file( $output ) ) {
				unlink( $output );
			}
		};

		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static fn ( array $stages ): array => [ ...$stages, $stage ]
		);

		$runner = new StageRunner(
			$store,
			new StageRegistry(),
			new RecordingScheduler(),
			new class() implements JobLock {
				public bool $released = false;

				public function acquire(): bool {
					return true;
				}

				public function release(): void {
					$this->released = true;
				}
			}
		);

		$runner->step( 'free-uuid' );

		$after = $store->findByUuid( 'free-uuid' );

		$cleanup();

		$this->assertNotNull( $after );
		$this->assertSame( 'counting', $after->stage );
	}
	/**
	 * ISC-356 — a stage's own write to the job row survives the step.
	 *
	 * The runner loads the job once at the top of a step and saves it again at
	 * the bottom. A stage that writes the row in between — FinalizeStage
	 * records the backup's total size — had that write reverted by the save of
	 * the stale copy. It was found live: a completed backup reporting zero
	 * bytes while its volumes on disk summed to 101,463.
	 */
	public function test_a_stage_that_writes_the_job_row_is_not_reverted(): void {
		$jobs      = new InMemoryJobStore();
		$scheduler = new RecordingScheduler();

		fiction_drafts_test_add_filter(
			StageRegistry::FILTER,
			static fn ( array $registered ): array => array_merge(
				$registered,
				[ new WritingStage( $jobs, 4242 ) ]
			)
		);

		$job = $jobs->insert(
			new BackupJob(
				uuid: 'writer-job',
				profile: BackupProfile::Full,
				status: JobStatus::Queued,
				createdAt: '2026-08-28 00:00:00',
				updatedAt: '2026-08-28 00:00:00'
			)
		);

		( new StageRunner( $jobs, new StageRegistry(), $scheduler ) )->step( $job->uuid );

		$final = $jobs->findByUuid( $job->uuid );

		$this->assertInstanceOf( BackupJob::class, $final );
		$this->assertSame( JobStatus::Completed, $final->status );
		$this->assertSame( 4242, $final->sizeBytes, 'the runner reverted the stage\'s own write' );
	}
}
