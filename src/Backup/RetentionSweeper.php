<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Domain\Settings;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\SettingsRepository;

/**
 * Keeps the newest N completed backups and removes the rest.
 *
 * Runs daily on `fiction_drafts/retention_sweep`, which Scheduler already
 * registers.  Deletion is the one thing in this plugin that destroys data the
 * administrator asked for, so it is bounded three ways:
 *
 * 1. **Only completed jobs are candidates.** A `queued` or `running` job is
 *    never counted and never deleted — deleting the volumes of a backup that
 *    is still being written is how a sweep corrupts the thing it is tidying.
 * 2. **Zero means keep everything.** The setting's default is 5, but a site
 *    that sets 0 has said "I will manage this myself", and a sweep that read
 *    that as "keep none" would delete every backup on the site. An absent,
 *    empty, or unparseable option lands on the default rather than on zero, so
 *    both ways of meaning "unset" fail safe.
 * 3. **Every path goes through BackupRemover.** VolumeNaming builds names from
 *    the job's own uuid, and removal refuses anything outside the storage root,
 *    so a corrupted row cannot become a general-purpose deleter.  The sweep and
 *    the REST delete route share that one definition of what a backup is made
 *    of, rather than each remembering four of its five parts.
 */
final class RetentionSweeper {

	public function __construct(
		private readonly JobStore $jobs,
		private readonly BackupRemover $remover,
		private readonly ?SettingsRepository $settings = null
	) {}

	public function register(): void {
		// Wrapped rather than passed directly: sweep() reports how many
		// backups it removed, which callers and tests want, and an action
		// callback must return nothing.
		add_action(
			Scheduler::HOOK_RETENTION,
			function (): void {
				$this->sweep();
			}
		);
	}

	/**
	 * Delete the oldest completed backups beyond the keep count.
	 *
	 * @return int How many backups were removed.
	 */
	public function sweep(): int {
		$removed = $this->clearFailedArtifacts();

		$keep = $this->keepCount();

		if ( $keep <= 0 ) {
			return $removed;
		}

		$completed = $this->completedNewestFirst();

		if ( count( $completed ) <= $keep ) {
			return $removed;
		}

		foreach ( array_slice( $completed, $keep ) as $job ) {
			$this->remover->remove( $job );
			++$removed;
		}

		return $removed;
	}

	/**
	 * Free the disk a failed job is still holding.
	 *
	 * A failed job's volumes are an archive that stops part-way through, and
	 * nothing in this plugin ever resumes one — the watchdog moves an abandoned
	 * job to `failed`, and a failed job is finished. Left alone they accumulate
	 * for ever, because the keep-N policy only ever looked at completed
	 * backups: a slow disk leak that first shows up in production months later.
	 *
	 * The row stays. The error message on it is the only record of what went
	 * wrong, and it costs one row rather than gigabytes.
	 */
	private function clearFailedArtifacts(): int {
		$removed = 0;

		foreach ( $this->jobs->all( JobStatus::Failed, 500 ) as $job ) {
			if ( $this->remover->removeArtifacts( $job ) > 0 ) {
				++$removed;

				do_action( 'fiction_drafts/backup_deleted', $job );
			}
		}

		return $removed;
	}

	/**
	 * Completed jobs, newest first.
	 *
	 * `all()` already orders by id descending, which for an auto-increment key
	 * is creation order.  Sorting on `created_at` instead would tie two backups
	 * taken in the same second and make the sweep's choice arbitrary.
	 *
	 * @return array<int, BackupJob>
	 */
	private function completedNewestFirst(): array {
		return $this->jobs->all( JobStatus::Completed, 500 );
	}

	private function keepCount(): int {
		$settings = $this->settings?->get();

		return $settings instanceof Settings
			? $settings->retentionCount()
			: Settings::DEFAULT_RETENTION_COUNT;
	}
}
