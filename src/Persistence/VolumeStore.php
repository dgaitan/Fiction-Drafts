<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;

/**
 * The ledger of finished volumes.
 *
 * An interface for the same reason JobStore is one: FinalizeStage's behaviour
 * has to be provable without a database, so that what a test demonstrates is
 * the stage's logic rather than MySQL's.
 */
interface VolumeStore {

	/**
	 * Replace this job's volume rows with the ones given.
	 *
	 * Replace rather than append, because finalizing can run twice — Action
	 * Scheduler retries an action whose request died after the work but before
	 * the acknowledgement — and a second append would double the ledger while
	 * the disk stayed the same.
	 *
	 * @param array<int, ArchiveVolume> $volumes Sealed volumes, in sequence order.
	 */
	public function replaceFor( BackupJob $job, array $volumes ): void;

	/**
	 * @return array<int, ArchiveVolume>
	 */
	public function allFor( BackupJob $job ): array;

	public function deleteFor( BackupJob $job ): void;
}
