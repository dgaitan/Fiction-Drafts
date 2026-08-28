<?php

declare( strict_types=1 );

namespace FictionDrafts\Contracts;

use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;

/**
 * One phase of a backup.
 *
 * THE INVARIANT — every implementation must hold to this:
 *
 *   run() receives a TimeBudget and MUST return before it is exhausted,
 *   handing back a StageCursor that describes exactly where to resume.
 *   A stage that cannot be interrupted is not a stage.
 *
 * In practice that means a loop over units of work which checks
 * $budget->exhausted() at the top of each iteration and returns
 * StageResult::incomplete( $cursor, $processed ) the moment it is true.
 *
 * Stages are registered through the `fiction_drafts/stages` filter — never a
 * hard-coded array — so that new phases (an upload stage, an encryption
 * stage) are additive.
 */
interface Stage {

	/**
	 * Stable machine identifier, persisted on the job row.
	 */
	public function id(): string;

	/**
	 * Translated, user-facing label for the progress bar.
	 */
	public function label(): string;

	/**
	 * Does this stage have anything to do for this job?
	 *
	 * A stage that does not apply is skipped without being run — a
	 * database-only job never enters the file stages at all.
	 */
	public function appliesTo( BackupJob $job ): bool;

	/**
	 * Do as much work as the budget allows, then stop.
	 */
	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult;
}
