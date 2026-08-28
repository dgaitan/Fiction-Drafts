<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;

/**
 * A stage that writes N numbered lines, and can be interrupted anywhere.
 *
 * This is the reference implementation of the forward-progress rule, and the
 * subject of the resumability proof.  Note the loop shape: the unit of work
 * happens first, and only then is the clock consulted.  A `while` that checked
 * the budget before doing anything would return zero-processed under an
 * already-exhausted budget, and the job would never advance.
 *
 * Every real stage in Sprints 3 to 5 is written against this shape.
 */
final class CountingStage implements Stage {

	public int $runs = 0;

	public function __construct(
		private readonly int $units,
		private readonly string $outputPath,
		private readonly string $id = 'counting',
		private readonly bool $applies = true
	) {}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return 'Counting to ' . $this->units;
	}

	public function appliesTo( BackupJob $job ): bool {
		return $this->applies;
	}

	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		++$this->runs;

		$index     = $cursor->getInt( 'index', 0 );
		$processed = 0;

		do {
			if ( $index >= $this->units ) {
				return StageResult::complete( $processed );
			}

			file_put_contents( $this->outputPath, $index . "\n", FILE_APPEND );

			++$index;
			++$processed;
		} while ( ! $budget->exhausted() );

		if ( $index >= $this->units ) {
			return StageResult::complete( $processed );
		}

		return StageResult::incomplete( StageCursor::fromArray( [ 'index' => $index ] ), $processed );
	}
}
