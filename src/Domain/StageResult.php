<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * What a stage hands back after one bounded run.
 *
 * `complete` is the only thing that advances the pipeline.  A stage that ran
 * out of time returns incomplete() with the cursor to resume from, and the
 * runner schedules another step.
 */
final class StageResult {

	private function __construct(
		public readonly StageCursor $cursor,
		public readonly int $processed,
		public readonly bool $complete,
		public readonly ?int $total = null
	) {
	}

	/**
	 * The stage finished all of its work.
	 *
	 * @param int      $processed Units of work done in this run.
	 * @param int|null $total     How many units this stage has in total, when it can say.
	 */
	public static function complete( int $processed = 0, ?int $total = null ): self {
		return new self( StageCursor::start(), $processed, true, $total );
	}

	/**
	 * The stage stopped early and must be resumed from $cursor.
	 *
	 * A stage that can estimate its own size reports it here rather than being
	 * asked for it separately.  Null means "I do not know yet", which is the
	 * honest answer for a scan that has not finished counting — and is why the
	 * runner leaves the job's existing total alone rather than zeroing it.
	 *
	 * @param int      $processed Units of work done in this run.
	 * @param int|null $total     How many units this stage has in total, when it can say.
	 */
	public static function incomplete( StageCursor $cursor, int $processed, ?int $total = null ): self {
		return new self( $cursor, $processed, false, $total );
	}
}
