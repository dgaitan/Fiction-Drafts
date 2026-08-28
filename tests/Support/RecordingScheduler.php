<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Backup\Scheduler;

/**
 * A scheduler that records enqueues instead of making them.
 *
 * The resumability proof drives the loop itself — one step per recorded
 * enqueue — which is what makes the step count observable and the whole run
 * deterministic.  A real queue would make both unknowable.
 */
final class RecordingScheduler extends Scheduler {

	/**
	 * @var array<int, string>
	 */
	public array $enqueued = [];

	/**
	 * @var array<int, string>
	 */
	public array $unscheduled = [];

	public function isAvailable(): bool {
		return true;
	}

	public function enqueueStep( string $uuid ): int {
		$this->enqueued[] = $uuid;

		return count( $this->enqueued );
	}

	public function scheduleRecurring(): void {
		// Nothing to schedule in a test.
	}

	public function unscheduleJob( string $uuid ): void {
		$this->unscheduled[] = $uuid;
	}

	public function unscheduleAll(): void {
		$this->unscheduled[] = '*';
	}

	public function hasPendingStep( string $uuid ): bool {
		return in_array( $uuid, $this->enqueued, true );
	}
}
