<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Persistence\JobStore;
use Throwable;

/**
 * Turns an Action Scheduler failure into a failed job.
 *
 * Without this, a step that fatals leaves its job sitting at `running`
 * forever, the progress bar frozen, and no way for the administrator to learn
 * why.  The watchdog would eventually catch it, but fifteen minutes later and
 * with a far less useful message than the exception itself.
 */
final class FailureHandler {

	public function __construct(
		private readonly JobStore $jobs,
		private readonly StageRunner $runner
	) {}

	/**
	 * The uuid of the job this request is currently stepping, if any.
	 *
	 * Set by StageRunner so the shutdown handler knows what to blame.
	 */
	private ?string $stepping = null;

	public function register(): void {
		add_action( 'action_scheduler_failed_execution', [ $this, 'onFailedExecution' ], 10, 2 );
		add_action( 'action_scheduler_failed_action', [ $this, 'onTimedOutAction' ], 10, 2 );
		add_action( 'fiction_drafts/stepping', [ $this, 'onSteppingStarted' ] );

		register_shutdown_function( [ $this, 'onShutdown' ] );
	}

	public function onSteppingStarted( string $uuid ): void {
		$this->stepping = $uuid;
	}

	/**
	 * Catch the failures that never throw.
	 *
	 * A memory exhaustion or a max_execution_time overrun is a fatal, not an
	 * exception: no catch block runs, Action Scheduler's failure hooks do not
	 * fire, and the job sits at `running` until the watchdog trips fifteen
	 * minutes later with a message that says only "it stopped responding".
	 * Reading error_get_last() at shutdown turns that into the actual error,
	 * on the actual line, which is the difference between a bug report someone
	 * can act on and one nobody can.
	 */
	public function onShutdown(): void {
		if ( null === $this->stepping ) {
			return;
		}

		$error = error_get_last();

		if ( null === $error || ! in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			return;
		}

		$job = $this->jobs->findByUuid( $this->stepping );

		if ( null === $job || $job->status->isTerminal() ) {
			return;
		}

		$this->runner->fail(
			$job,
			sprintf( '%s in %s on line %d', $error['message'], $error['file'], $error['line'] )
		);
	}

	/**
	 * @param int|string $actionId Action Scheduler's action id.
	 */
	public function onFailedExecution( $actionId, Throwable $error ): void {
		$this->failJobFor( $actionId, $error->getMessage() );
	}

	/**
	 * @param int|string $actionId Action Scheduler's action id.
	 * @param int        $timeout  Seconds after which the action was abandoned.
	 */
	public function onTimedOutAction( $actionId, $timeout ): void {
		$this->failJobFor(
			$actionId,
			sprintf( 'The backup step was abandoned after %d seconds without finishing.', (int) $timeout )
		);
	}

	/**
	 * Fail the job an action belongs to — if the action is even ours.
	 *
	 * @param int|string $actionId Action Scheduler's action id.
	 */
	private function failJobFor( $actionId, string $message ): void {
		$uuid = $this->uuidFor( $actionId );

		if ( null === $uuid ) {
			return;
		}

		$job = $this->jobs->findByUuid( $uuid );

		if ( null === $job || $job->status->isTerminal() ) {
			return;
		}

		$this->runner->fail( $job, $message );
	}

	/**
	 * The job uuid an action refers to, or null when the action is not ours.
	 *
	 * @param int|string $actionId Action Scheduler's action id.
	 */
	private function uuidFor( $actionId ): ?string {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return null;
		}

		try {
			$action = \ActionScheduler::store()->fetch_action( (string) $actionId );
		} catch ( Throwable ) {
			return null;
		}

		if ( Scheduler::HOOK_RUN_STEP !== $action->get_hook() ) {
			return null;
		}

		$args = $action->get_args();
		$uuid = $args[0] ?? null;

		return is_string( $uuid ) && '' !== $uuid ? $uuid : null;
	}
}
