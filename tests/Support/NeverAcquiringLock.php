<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Backup\JobLock;

/**
 * A lock nobody can take.
 *
 * The fail-closed branches — issue refusing to hand back a token it could not
 * store, consume refusing a token it cannot prove unspent — are only reachable
 * when the lock is unavailable, and a real lock is available in a test. Without
 * this double those branches are code nothing has ever executed.
 */
final class NeverAcquiringLock implements JobLock {

	public function acquire(): bool {
		return false;
	}

	public function release(): void {}
}
