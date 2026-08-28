<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Persistence\JobStore;

/**
 * A stage that writes the job row itself, the way FinalizeStage does.
 *
 * FinalizeStage records the backup's total size, because sealing the volumes
 * is the first moment every one of them has been measured. The runner holds a
 * copy of the job loaded before the stage ran, so unless it re-reads, its next
 * save reverts that write — a completed backup that reports a size of zero.
 */
final class WritingStage implements Stage {

	public function __construct(
		private readonly JobStore $jobs,
		private readonly int $sizeBytes = 4242,
		private readonly string $id = 'writing'
	) {}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return 'Writing the size';
	}

	public function appliesTo( BackupJob $job ): bool {
		return true;
	}

	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		$this->jobs->save( $job->with( [ 'sizeBytes' => $this->sizeBytes ] ) );

		return StageResult::complete( 1, 1 );
	}
}
