<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

/**
 * A mutual exclusion primitive around starting a job.
 *
 * "Only one job at a time" cannot be enforced by a read followed by a write:
 * two administrators clicking Back up at the same moment both see no active
 * job, and both insert one. The window is small and the consequence is two
 * backups competing for the same disk and memory — the failure spec 12 lists
 * and REST answers with 409.
 *
 * An interface rather than a concrete class for the same reason JobStore is:
 * the engine's tests must not need a database.
 */
interface JobLock {

	/**
	 * Try to take the lock without waiting.
	 *
	 * @return bool True when this caller now holds it.
	 */
	public function acquire(): bool;

	public function release(): void;
}
