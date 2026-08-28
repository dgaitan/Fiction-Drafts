<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Persistence\JobStore;
use Throwable;

/**
 * The only thing that ever calls Stage::run().
 *
 * One step: load the job, resolve its stage and cursor, run it against a time
 * budget, persist what came back, and either advance or re-enqueue.  Every
 * piece of state a resume depends on is written here and nowhere else, so
 * there is exactly one place where resumability can be got wrong.
 *
 * ## The forward-progress rule
 *
 * The spec's invariant says a stage checks `exhausted()` at the top of each
 * unit and returns the moment it is true.  Read literally, a budget that is
 * already exhausted produces a step that does zero units and returns an
 * unchanged cursor — and the runner re-enqueues it, forever.  The sprint's own
 * acceptance criterion (1,000 units at a zero-second budget) is exactly that
 * case.
 *
 * So the invariant needs a second half, enforced at two layers:
 *
 * 1. A stage does at least one unit of work before consulting the clock.
 *    Forward progress becomes structural rather than hoped-for.
 * 2. This runner refuses to re-enqueue an unfinished step that handed back the
 *    cursor it was given, and fails the job with a stated reason.
 *
 * The second layer exists because stages arrive through a public filter.  A
 * third-party stage that gets rule 1 wrong should produce a failed job with a
 * clear message, not an infinite queue.
 */
final class StageRunner {

	public function __construct(
		private readonly JobStore $jobs,
		private readonly StageRegistry $stages,
		private readonly Scheduler $scheduler,
		private readonly ?JobLock $lock = null
	) {}

	/**
	 * Run one bounded step of a job.
	 *
	 * Safe to call for a uuid that does not exist or a job that has already
	 * finished: Action Scheduler can retry an action after the job it refers
	 * to was cancelled, and that must not be an error.
	 */
	public function step( string $uuid ): void {
		// Two workers on one job is not a hypothetical: WP-Cron overlapping an
		// admin-ajax tick is routine.  Through Sprint 3 that was merely
		// wasteful — a repeated batch overwrote itself.  From Sprint 4 it is
		// destructive, because resuming an archive *mutates* it: both workers
		// truncate to the same entry count and both add again.  Failing to
		// take the lock means someone else is inside this job, and they will
		// re-enqueue when they finish, so returning is the whole handling.
		if ( null !== $this->lock && ! $this->lock->acquire() ) {
			return;
		}

		try {
			$this->runStep( $uuid );
		} finally {
			$this->lock?->release();
		}
	}

	private function runStep( string $uuid ): void {
		$job = $this->jobs->findByUuid( $uuid );

		if ( null === $job || $job->status->isTerminal() ) {
			return;
		}

		// Announce the job under way so FailureHandler's shutdown handler can
		// attribute a fatal — which never reaches a catch block — to this job.
		do_action( 'fiction_drafts/stepping', $uuid );

		$pipeline = $this->stages->applicableTo( $job );

		if ( [] === $pipeline ) {
			$this->finish( $job );

			return;
		}

		$job   = $this->begin( $job, $pipeline[0] );
		$stage = $this->stages->find( $job, (string) $job->stage );

		if ( null === $stage ) {
			// The stage id on the job no longer resolves — a plugin that
			// registered it was deactivated mid-job.  Failing loudly beats
			// silently skipping work the archive is supposed to contain.
			$this->fail( $job, sprintf( 'Stage "%s" is no longer registered.', (string) $job->stage ) );

			return;
		}

		$this->execute( $job, $stage );
	}

	/**
	 * Move a queued job into running, and give it its first stage.
	 */
	private function begin( BackupJob $job, Stage $first ): BackupJob {
		if ( null !== $job->stage && JobStatus::Running === $job->status ) {
			return $job;
		}

		return $this->jobs->save(
			$job->with(
				[
					'status'    => JobStatus::Running,
					'stage'     => $job->stage ?? $first->id(),
					'updatedAt' => self::now(),
				]
			)
		);
	}

	private function execute( BackupJob $job, Stage $stage ): void {
		$before = $job->cursor();
		$budget = new TimeBudget( (int) apply_filters( 'fiction_drafts/time_budget_seconds', TimeBudget::DEFAULT_SECONDS ) );

		try {
			$result = $stage->run( $job, $before, $budget );
		} catch ( Throwable $error ) {
			$this->fail( $job, $error->getMessage() );

			return;
		}

		// Re-read before doing anything with $job again.
		//
		// A stage may write the job row itself — FinalizeStage records the
		// backup's total size, which is the first moment every volume has been
		// measured. The copy loaded at the top of this step predates that
		// write, and saving it back silently reverts the field. Measured, not
		// theorised: the live run reported a completed backup with a size of
		// zero while the volumes on disk added up to 101,463 bytes.
		//
		// Fixed here rather than in the stage, because the same trap is set for
		// every stage that ever writes, including ones this plugin will never
		// see. After run() returns, the store is the truth.
		$job = $this->jobs->findByUuid( $job->uuid ) ?? $job;

		// Compare-and-swap on the status, at the one boundary where it is safe.
		//
		// Cancellation writes `cancelled` to the row from another process while
		// this one is inside `run()`. Through Sprint 6 the runner then wrote its
		// own copy back, and a job the administrator cancelled came back to life
		// as `running` and kept archiving — the cancel was a race the runner
		// usually won by accident of timing.
		//
		// The row read *after* the stage returned is the authority. If it left
		// `running` while this step was in flight, the step's result is
		// discarded and nothing is re-enqueued: whoever changed the status has
		// already done the cleanup, and this process's job is to stop. The work
		// done in this step is lost, which is the correct trade — a cancelled
		// backup does not want its next batch.
		//
		// This is the complete fix the delete route's shared lock was only the
		// cheap half of, and it subsumes the cancellation item Sprint 5 deferred.
		if ( JobStatus::Running !== $job->status ) {
			do_action( 'fiction_drafts/step_abandoned', $job );

			return;
		}

		if ( $result->complete ) {
			$this->advance( $job, $stage, $result );

			return;
		}

		// Layer 2 of the forward-progress rule.
		//
		// The cursor is the entire definition of "where to resume". An
		// unfinished step that hands back the same cursor it was given will be
		// handed that cursor again, and will produce the same result, for as
		// long as the queue keeps running it.
		//
		// The test is the cursor alone, deliberately — not the cursor *and* a
		// zero processed count. A stage that reports work done while leaving
		// the resume position untouched is in a worse state than one that
		// reports nothing: it loops *and* repeats whatever it claims to have
		// done, every time round.
		if ( $result->cursor->toJson() === $before->toJson() ) {
			$this->fail(
				$job,
				sprintf(
					'Stage "%s" returned an unchanged cursor without completing. A stage must either finish or advance its resume position on every step.',
					$stage->id()
				)
			);

			return;
		}

		// The cursor is persisted only after the work it describes is done, so
		// a crash between the work and the write costs a repeat of one batch,
		// never a silently skipped one.
		$changes = [
			'cursor'    => $result->cursor,
			'processed' => $job->processed + $result->processed,
			'updatedAt' => self::now(),
		];

		// A stage reports its own size when it can work one out.  Null means it
		// cannot yet, and overwriting a known total with zero would make the
		// progress bar forget what it already knew.
		if ( null !== $result->total ) {
			$changes['total'] = $result->total;
		}

		$this->jobs->save( $job->with( $changes ) );

		$this->scheduler->enqueueStep( $job->uuid );
	}

	/**
	 * A stage finished.  Hand the job to the next one, or complete it.
	 *
	 * `processed` and `total` both reset on advance because each stage counts
	 * in its own units — rows, then files, then archive entries — and a
	 * progress bar that mixed them would be meaningless.  The REST payload
	 * exposes these as `stage_processed` / `stage_total` for exactly that
	 * reason, alongside a whole-job figure that does not jump backwards.
	 */
	private function advance( BackupJob $job, Stage $stage, StageResult $result ): void {
		$next = $this->stages->next( $job, $stage->id() );

		if ( null === $next ) {
			$changes = [ 'processed' => $job->processed + $result->processed ];

			if ( null !== $result->total ) {
				$changes['total'] = $result->total;
			}

			$this->finish( $job->with( $changes ) );

			return;
		}

		$this->jobs->save(
			$job->with(
				[
					'stage'     => $next->id(),
					'cursor'    => StageCursor::start(),
					'processed' => 0,
					'total'     => 0,
					'updatedAt' => self::now(),
				]
			)
		);

		$this->scheduler->enqueueStep( $job->uuid );
	}

	private function finish( BackupJob $job ): void {
		$completed = $this->jobs->save(
			$job->with(
				[
					'status'      => JobStatus::Completed,
					'cursor'      => StageCursor::start(),
					'updatedAt'   => self::now(),
					'completedAt' => self::now(),
				]
			)
		);

		do_action( 'fiction_drafts/job_completed', $completed );
	}

	/**
	 * Mark a job failed with a message an administrator can act on.
	 */
	public function fail( BackupJob $job, string $message ): void {
		$failed = $this->jobs->save(
			$job->with(
				[
					'status'    => JobStatus::Failed,
					'error'     => $message,
					'updatedAt' => self::now(),
				]
			)
		);

		$this->scheduler->unscheduleJob( $job->uuid );

		do_action( 'fiction_drafts/job_failed', $failed, $message );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}
}
