<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

/**
 * Every interaction with Action Scheduler goes through here.
 *
 * One wrapper means one place that knows the group name, and one place that
 * checks the `as_*` functions exist before calling them.  That check is not
 * defensive noise: unit tests run without WordPress, and uninstall runs at a
 * moment when the library may already be gone.
 *
 * Not final: the resumability proof substitutes a recording subclass so that
 * enqueues are observable and the test can drive the step loop itself.
 *
 * The dedicated group is what makes uninstall safe —
 * `as_unschedule_all_actions( '', [], 'fiction-drafts' )` removes exactly this
 * plugin's actions and nothing anyone else scheduled.
 */
class Scheduler {

	public const GROUP = 'fiction-drafts';

	public const HOOK_RUN_STEP = 'fiction_drafts/run_step';

	public const HOOK_WATCHDOG = 'fiction_drafts/stale_job_watchdog';

	public const HOOK_RETENTION = 'fiction_drafts/retention_sweep';

	public const WATCHDOG_INTERVAL = 300;

	/**
	 * One day, as a literal rather than DAY_IN_SECONDS: this is a class
	 * constant, so it is evaluated when the file loads — including in unit
	 * tests, which run without WordPress defining that constant.
	 */
	public const RETENTION_INTERVAL = 86400;

	public function isAvailable(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Queue one bounded step for this job.
	 *
	 * Async rather than scheduled-at-a-time: the queue should pick it up as
	 * soon as it can, and the step itself decides whether another follows.
	 */
	public function enqueueStep( string $uuid ): int {
		if ( ! $this->isAvailable() ) {
			return 0;
		}

		return (int) as_enqueue_async_action( self::HOOK_RUN_STEP, [ $uuid ], self::GROUP );
	}

	/**
	 * Register the recurring maintenance actions, if they are not already.
	 */
	public function scheduleRecurring(): void {
		if ( ! $this->isAvailable() ) {
			return;
		}

		if ( false === as_has_scheduled_action( self::HOOK_WATCHDOG, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::WATCHDOG_INTERVAL, self::WATCHDOG_INTERVAL, self::HOOK_WATCHDOG, [], self::GROUP );
		}

		if ( false === as_has_scheduled_action( self::HOOK_RETENTION, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::RETENTION_INTERVAL, self::RETENTION_INTERVAL, self::HOOK_RETENTION, [], self::GROUP );
		}
	}

	/**
	 * Drop every pending step for one job, leaving other jobs alone.
	 */
	public function unscheduleJob( string $uuid ): void {
		if ( ! $this->isAvailable() ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_RUN_STEP, [ $uuid ], self::GROUP );
	}

	/**
	 * Drop everything this plugin scheduled.  Used by deactivate and uninstall.
	 */
	public function unscheduleAll(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( '', [], self::GROUP );
	}

	public function hasPendingStep( string $uuid ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return as_has_scheduled_action( self::HOOK_RUN_STEP, [ $uuid ], self::GROUP );
	}
}
