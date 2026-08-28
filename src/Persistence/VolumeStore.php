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

	/**
	 * Volumes for many jobs at once, keyed by job uuid.
	 *
	 * The list route renders up to a hundred backups in one response, and
	 * calling `allFor()` per job made that a hundred round trips to MySQL for
	 * a screen that is the first thing the page loads. The rows are one
	 * `IN (…)` away from each other.
	 *
	 * Every uuid asked for appears in the result, mapped to an empty list when
	 * the job has no volumes — so a caller never has to distinguish "no rows"
	 * from "not asked".
	 *
	 * @param  array<int, BackupJob>                  $jobs Jobs to look up.
	 * @return array<string, array<int, ArchiveVolume>>
	 */
	public function allForMany( array $jobs ): array;

	public function deleteFor( BackupJob $job ): void;
}
