<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Storage\StorageLocator;
use RuntimeException;

/**
 * Creates, cancels, and refuses jobs.
 *
 * Transport-agnostic on purpose: the REST controller translates its exceptions
 * into status codes, and the WP-CLI command planned for v0.2.0 will translate
 * the same exceptions into exit codes, without either of them re-implementing
 * a rule.
 */
final class JobManager {

	/**
	 * Another job is already queued or running.  REST maps this to 409.
	 */
	public const REASON_ALREADY_ACTIVE = 'already_active';

	/**
	 * The requested job would copy nothing at all.  REST maps this to 422.
	 */
	public const REASON_NOTHING_SELECTED = 'nothing_selected';

	public function __construct(
		private readonly JobStore $jobs,
		private readonly Scheduler $scheduler,
		private readonly StorageLocator $storage,
		private readonly ?JobLock $lock = null
	) {}

	/**
	 * Start a backup.
	 *
	 * @param  array<string, mixed> $options Per-job choices, including the
	 *                                       wp-config opt-in and, for Custom,
	 *                                       the per-area selections.
	 * @throws RuntimeException When another job is active, or when the request
	 *                          selects no content at all.
	 */
	public function create( BackupProfile $profile, array $options = [] ): BackupJob {
		// "Is one already running?" followed by "insert one" is not atomic.
		// Two administrators clicking Back up together both read no active job
		// and both insert. Holding a lock across the pair closes that window;
		// failing to get the lock means someone else is inside it, which is
		// the same answer as finding an active job.
		if ( null !== $this->lock && ! $this->lock->acquire() ) {
			throw new RuntimeException( self::REASON_ALREADY_ACTIVE );
		}

		try {
			return $this->createExclusively( $profile, $options );
		} finally {
			if ( null !== $this->lock ) {
				$this->lock->release();
			}
		}
	}

	/**
	 * @param  array<string, mixed> $options Per-job choices.
	 * @throws RuntimeException When another job is active, or nothing is selected.
	 */
	private function createExclusively( BackupProfile $profile, array $options ): BackupJob {
		$active = $this->jobs->findActive();

		if ( null !== $active ) {
			throw new RuntimeException( self::REASON_ALREADY_ACTIVE );
		}

		$job = new BackupJob(
			uuid: wp_generate_uuid4(),
			profile: $profile,
			status: JobStatus::Queued,
			options: $options,
			createdAt: self::now(),
			updatedAt: self::now()
		);

		// A job that selects nothing would produce an empty archive and report
		// success.  Refusing it is the difference between a visible error and
		// silent data loss.
		if ( ! $job->selectsAnyContent() ) {
			throw new RuntimeException( self::REASON_NOTHING_SELECTED );
		}

		$this->storage->ensure();

		$stored = $this->jobs->insert( $job );

		$this->scheduler->enqueueStep( $stored->uuid );

		return $stored;
	}

	/**
	 * Stop a job and clean up after it.
	 */
	public function cancel( string $uuid ): ?BackupJob {
		$job = $this->jobs->findByUuid( $uuid );

		if ( null === $job || $job->status->isTerminal() ) {
			return $job;
		}

		$this->scheduler->unscheduleJob( $uuid );

		$cancelled = $this->jobs->save(
			$job->with(
				[
					'status'    => JobStatus::Cancelled,
					'updatedAt' => self::now(),
				]
			)
		);

		$this->storage->removeDirectory( $this->storage->workingDir( $uuid ) );

		// The partial volumes go too.  A cancelled job's archive is missing
		// whatever had not been reached yet, and a truncated archive left in
		// the storage root looks exactly like a finished one to a directory
		// listing, to the retention sweep, and to whoever downloads it.
		VolumeNaming::forStorage( $this->storage )->removeAllFor( $cancelled );

		return $cancelled;
	}

	public function find( string $uuid ): ?BackupJob {
		return $this->jobs->findByUuid( $uuid );
	}

	public function active(): ?BackupJob {
		return $this->jobs->findActive();
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}
}
