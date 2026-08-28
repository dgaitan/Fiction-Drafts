<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup\Stages;

use FictionDrafts\Backup\Manifest;
use FictionDrafts\Backup\Preflight;
use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Storage\StorageLocator;
use RuntimeException;

/**
 * The gate between measuring a backup and writing one.
 *
 * ## Why this runs fourth and not first
 *
 * The plan calls this "preflight", which suggests it belongs at the front of
 * the pipeline.  It does not, and the reason is arithmetic rather than taste.
 *
 * The thing worth preventing is filling the disk.  Nothing large is written
 * before the archive: the dump is bounded by the database and `files.jsonl` is
 * a list of paths.  So the last safe moment to refuse is immediately before
 * ArchiveStage opens its first volume — and that is also the *first* moment a
 * real number exists, because the scan has just finished counting bytes.
 *
 * Running this first would mean gating on an estimate when a measurement is
 * thirty seconds away.  A gate on a guess is decoration: it fires on sites
 * that were fine and stays quiet on the one that fills the disk.
 *
 * The writability check is here for a different reason — by this point the
 * earlier stages have already written to the storage root, so a failure here
 * is a permission that changed mid-run, which is worth saying out loud.
 *
 * ## What it leaves behind
 *
 * `manifest.json`, in the working directory, where ArchiveStage's EXTRAS list
 * already looks for it.  That copy carries an empty `volumes` array, because
 * it is added to the archive before any volume is sealed and a file cannot
 * carry its own hash.  FinalizeStage writes the complete copy beside the
 * volumes once they are closed.
 */
final class PrepareStage implements Stage {

	public const ID = 'prepare';

	public function __construct(
		private readonly StorageLocator $storage,
		private readonly Preflight $preflight,
		private readonly Manifest $manifest
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Checking there is room', 'fiction-drafts' );
	}

	/**
	 * Every job is checked and every job gets a manifest, including a
	 * database-only one — an archive nobody can identify later is the problem
	 * this stage exists to prevent, and that is not profile-specific.
	 */
	public function appliesTo( BackupJob $job ): bool {
		return true;
	}

	/**
	 * One step, always.  There is nothing here to resume.
	 *
	 * @throws RuntimeException When the storage root is unusable or space is short.
	 */
	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		$this->preflight->assertWritable();

		$workingDir = $this->storage->workingDir( $job->uuid );
		$summary    = $this->scanSummary( $workingDir );

		$required = $this->preflight->requiredBytes(
			$workingDir . '/' . DatabaseStage::OUTPUT,
			$summary['bytes']
		);

		// Throwing rather than returning a failed result: StageRunner turns a
		// thrown stage into a failed job with the message intact, and a failed
		// job is never re-enqueued.  "Schedules no further steps" is a
		// consequence of failing, not something this stage has to arrange.
		$this->preflight->assertSpace( $required );

		$written = Manifest::write(
			$workingDir . '/' . Manifest::FILENAME,
			$this->manifest->build( $job, $summary['files'], $required, $summary['symlinks'] )
		);

		if ( ! $written ) {
			throw new RuntimeException(
				__( 'The backup manifest could not be written. Check the free space and permissions on wp-content.', 'fiction-drafts' )
			);
		}

		return StageResult::complete( 1, 1 );
	}

	/**
	 * What FileScanStage recorded, or zeroes for a job that never scanned.
	 *
	 * A database-only job has no scan, and zero files is the truthful answer
	 * for it rather than a missing-file error.
	 *
	 * @return array{files: int, bytes: int, symlinks: int}
	 */
	private function scanSummary( string $workingDir ): array {
		$summary = [
			'files'    => 0,
			'bytes'    => 0,
			'symlinks' => 0,
		];

		$decoded = Manifest::read( $workingDir . '/' . FileScanStage::SUMMARY );

		if ( null === $decoded ) {
			return $summary;
		}

		foreach ( array_keys( $summary ) as $key ) {
			if ( isset( $decoded[ $key ] ) && is_numeric( $decoded[ $key ] ) ) {
				$summary[ $key ] = (int) $decoded[ $key ];
			}
		}

		return $summary;
	}
}
