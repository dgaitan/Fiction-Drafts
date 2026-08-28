<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Persistence\JobStore;

/**
 * A job store that lives in an array.
 *
 * The resumability proof has to demonstrate the engine's behaviour, not
 * MySQL's.  Running it against this store means a failure can only be the
 * runner's fault, which is the entire point of the exercise.
 *
 * It also counts saves, so a test can assert that the cursor was written the
 * expected number of times rather than merely ending up correct.
 */
final class InMemoryJobStore implements JobStore {

	/**
	 * @var array<string, BackupJob>
	 */
	private array $jobs = [];

	public int $saves = 0;

	private int $nextId = 1;

	public function insert( BackupJob $job ): BackupJob {
		$stored = $job->with( [ 'id' => $this->nextId ] );
		++$this->nextId;

		$this->jobs[ $stored->uuid ] = $stored;

		return $stored;
	}

	public function save( BackupJob $job ): BackupJob {
		++$this->saves;

		if ( null === $job->id ) {
			return $this->insert( $job );
		}

		$this->jobs[ $job->uuid ] = $job;

		return $job;
	}

	public function findByUuid( string $uuid ): ?BackupJob {
		return $this->jobs[ $uuid ] ?? null;
	}

	public function findActive(): ?BackupJob {
		foreach ( $this->jobs as $job ) {
			if ( $job->status->isActive() ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * @return array<int, BackupJob>
	 */
	public function all( ?JobStatus $status = null, int $limit = 50 ): array {
		$matching = array_values(
			array_filter(
				$this->jobs,
				static fn ( BackupJob $job ): bool => null === $status || $job->status === $status
			)
		);

		// Newest first, matching JobRepository's `ORDER BY id DESC`. This used
		// to return insertion order, which is the opposite — and the retention
		// sweep keeps the first N, so a fake that ordered the other way would
		// have proved the sweep deleted the newest backups and called it a
		// pass. A double that differs from the real store on the one property
		// its consumer depends on is worse than no double.
		usort(
			$matching,
			static fn ( BackupJob $a, BackupJob $b ): int => ( $b->id ?? 0 ) <=> ( $a->id ?? 0 )
		);

		return array_slice( $matching, 0, $limit );
	}

	/**
	 * @return array<int, BackupJob>
	 */
	public function findStale( string $before ): array {
		return array_values(
			array_filter(
				$this->jobs,
				static fn ( BackupJob $job ): bool => JobStatus::Running === $job->status
					&& null !== $job->updatedAt
					&& $job->updatedAt < $before
			)
		);
	}

	public function delete( string $uuid ): bool {
		unset( $this->jobs[ $uuid ] );

		return true;
	}
}
