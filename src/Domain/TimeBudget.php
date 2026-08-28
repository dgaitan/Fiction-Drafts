<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * The clock a stage runs against.
 *
 * Every stage checks exhausted() at the top of each unit of work and returns
 * the moment it is true.  Two things can exhaust a budget: wall-clock time,
 * and memory headroom.  The second matters because one pathological row or
 * one enormous file should end a step cleanly, not fatally.
 */
final class TimeBudget {

	/**
	 * Seconds of wall clock a single step may use.
	 *
	 * Deliberately well under a typical 30-second max_execution_time: the step
	 * still has to persist its cursor and schedule its successor after the
	 * budget runs out.
	 */
	public const DEFAULT_SECONDS = 20;

	private float $startedAt;

	private ?int $memoryLimitBytes;

	public function __construct(
		private readonly int $seconds = self::DEFAULT_SECONDS,
		private readonly float $memoryCeilingRatio = 0.8
	) {
		$this->startedAt        = microtime( true );
		$this->memoryLimitBytes = self::resolveMemoryLimit();
	}

	/**
	 * Build a budget that respects this host's max_execution_time.
	 *
	 * A max_execution_time of 0 means "unlimited" (the CLI default).  That is
	 * not a licence to run forever — an unlimited step still blocks the queue —
	 * so the default ceiling applies.
	 */
	public static function fromEnvironment( int $ceiling = self::DEFAULT_SECONDS ): self {
		$maxExecution = (int) ini_get( 'max_execution_time' );

		$seconds = ( $maxExecution > 0 )
			? min( $ceiling, $maxExecution )
			: $ceiling;

		return new self( $seconds );
	}

	public function seconds(): int {
		return $this->seconds;
	}

	public function elapsed(): float {
		return microtime( true ) - $this->startedAt;
	}

	public function remaining(): float {
		return max( 0.0, (float) $this->seconds - $this->elapsed() );
	}

	/**
	 * Must the current step stop now?
	 */
	public function exhausted(): bool {
		if ( $this->elapsed() >= (float) $this->seconds ) {
			return true;
		}

		return $this->memoryExhausted();
	}

	public function memoryExhausted(): bool {
		if ( null === $this->memoryLimitBytes ) {
			return false;
		}

		$ceiling = (float) $this->memoryLimitBytes * $this->memoryCeilingRatio;

		return (float) memory_get_usage( true ) >= $ceiling;
	}

	/**
	 * Resolve memory_limit to bytes, or null when there is no limit.
	 */
	private static function resolveMemoryLimit(): ?int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return null;
		}

		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;

		return match ( $unit ) {
			'g'     => $value * 1024 * 1024 * 1024,
			'm'     => $value * 1024 * 1024,
			'k'     => $value * 1024,
			default => $value,
		};
	}
}
