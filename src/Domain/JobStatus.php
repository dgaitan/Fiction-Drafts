<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * The lifecycle of a single backup job.
 *
 * A job is always in exactly one of these states.  There is no "stuck" state:
 * the stale-job watchdog moves an abandoned `running` job to `failed`.
 */
enum JobStatus: string {

	case Queued    = 'queued';
	case Running   = 'running';
	case Completed = 'completed';
	case Failed    = 'failed';
	case Cancelled = 'cancelled';

	/**
	 * Is this job still expected to do work?
	 */
	public function isActive(): bool {
		return self::Queued === $this || self::Running === $this;
	}

	/**
	 * Has this job reached a state it will never leave?
	 */
	public function isTerminal(): bool {
		return ! $this->isActive();
	}
}
