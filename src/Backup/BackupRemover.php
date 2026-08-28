<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Storage\StorageLocator;

/**
 * The one definition of what removing a backup consists of.
 *
 * Two callers need it: the retention sweep, which removes on a policy, and the
 * REST controller, which removes because an administrator asked.  Before this
 * class existed only the sweep knew that a backup is five things — volumes,
 * sidecar manifest, working directory, volume rows, job row — and the second
 * implementation would have been written from whichever of the five the author
 * happened to remember.  A backup that is deleted from the list while its
 * volumes stay on disk is invisible *and* still occupying the disk, which is
 * the worst of the available outcomes.
 *
 * Order is load-bearing and is the same in both callers: files before rows.
 * Reversed, a failure between the two leaves files nothing knows about — the
 * sweep works from the rows, so a file whose row is gone is unreachable for
 * ever.
 */
final class BackupRemover {

	public function __construct(
		private readonly JobStore $jobs,
		private readonly VolumeStore $volumes,
		private readonly StorageLocator $storage
	) {}

	/**
	 * Remove everything belonging to this backup, including its job row.
	 *
	 * @return int How many files were unlinked from the storage root.
	 */
	public function remove( BackupJob $job ): int {
		$freed = $this->removeArtifacts( $job );

		$this->jobs->delete( $job->uuid );

		do_action( 'fiction_drafts/backup_deleted', $job );

		return $freed;
	}

	/**
	 * Remove the files and the volume ledger, but keep the job row.
	 *
	 * This is what a failed job gets swept with: the row is one row, and the
	 * error message on it is the only record of what went wrong, while the
	 * artifacts are an archive that stops part-way through and that nothing
	 * will ever resume.
	 *
	 * @return int How many files were unlinked from the storage root.
	 */
	public function removeArtifacts( BackupJob $job ): int {
		$freed = $this->naming()->removeAllFor( $job );

		$this->storage->removeDirectory( $this->storage->workingDir( $job->uuid ) );
		$this->volumes->deleteFor( $job );

		return $freed;
	}

	private function naming(): VolumeNaming {
		return VolumeNaming::forStorage( $this->storage );
	}
}
