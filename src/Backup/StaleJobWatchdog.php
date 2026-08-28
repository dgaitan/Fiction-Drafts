<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Persistence\JobStore;

/**
 * The last line of defence against a job that will never finish.
 *
 * A deploy, a restart, an OOM kill, or a queue that quietly stopped all leave
 * a job at `running` with nothing scheduled to advance it.  There is no
 * "stuck" status by design; the watchdog is what makes that true, by moving an
 * abandoned job to `failed` where the administrator can see it and start again.
 */
final class StaleJobWatchdog {

	/**
	 * How long a running job may go untouched before it is presumed dead.
	 *
	 * Comfortably longer than one step's 20-second budget plus queue latency,
	 * so an alive-but-slow job is never mistaken for an abandoned one.
	 */
	public const STALE_AFTER_SECONDS = 900;

	public function __construct(
		private readonly JobStore $jobs,
		private readonly StageRunner $runner
	) {}

	public function register(): void {
		add_action( Scheduler::HOOK_WATCHDOG, [ $this, 'sweep' ] );
	}

	public function sweep(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER_SECONDS );

		foreach ( $this->jobs->findStale( $cutoff ) as $job ) {
			$this->runner->fail(
				$job,
				sprintf(
					'The backup stopped responding and was abandoned after %d minutes without progress.',
					(int) ( self::STALE_AFTER_SECONDS / 60 )
				)
			);
		}
	}
}
