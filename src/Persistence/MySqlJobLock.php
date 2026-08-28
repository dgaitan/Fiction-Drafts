<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

use FictionDrafts\Backup\JobLock;

/**
 * A MySQL named lock, held for the length of one request.
 *
 * `GET_LOCK` was chosen over the two obvious alternatives. An option row used
 * as a mutex has no automatic release, so a fatal between acquire and release
 * wedges the plugin until someone deletes the row by hand. A UNIQUE index on a
 * generated column is the most correct answer, but `dbDelta()` does not handle
 * generated columns or functional indexes reliably and would fight the
 * migrator's idempotency on every run.
 *
 * A named lock is released automatically when the connection closes — which is
 * exactly the behaviour wanted after a PHP fatal, and the property neither
 * alternative has.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * -- Named locks are a server primitive with no WordPress API and nothing to cache.
 */
final class MySqlJobLock implements JobLock {

	/**
	 * @param string $purpose What this lock guards; part of the lock's name.
	 * @param int    $wait    Seconds to wait for the lock before giving up.
	 */
	public function __construct(
		private readonly string $purpose = 'job_create',
		private readonly int $wait = 0
	) {}

	/**
	 * Named for the database as well as the prefix.
	 *
	 * `GET_LOCK` names live on the MySQL *server*, not in a database — two
	 * WordPress installs on one server share the namespace. The table prefix
	 * alone is not enough to separate them, because the overwhelmingly common
	 * arrangement is several installs that all use `wp_`. Two unrelated sites
	 * would then block each other's backups, and on shared hosting a neighbour
	 * could hold a lock this plugin waits on. `DB_NAME` is what actually
	 * distinguishes them.
	 *
	 * Hashed rather than concatenated: MySQL truncates lock names at 64
	 * characters, and a long database name plus a long prefix silently collides
	 * again at exactly the point the name was meant to prevent it.
	 */
	private function name(): string {
		global $wpdb;

		$database = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';

		return 'fd_' . substr( md5( $database . '|' . $wpdb->prefix ), 0, 16 ) . '_' . $this->purpose;
	}

	public function acquire(): bool {
		global $wpdb;

		// The wait is the caller's decision, and the two callers want opposite
		// things.
		//
		// Starting a job, or running a step, wants zero: failing immediately is
		// the point, because the answer to "a backup is already running" is a
		// 409 rather than a request that hangs and then says the same thing.
		//
		// Claiming a download grant wants a few seconds. Its critical section is
		// one option read and one option write — microseconds — and two
		// administrators, or one administrator downloading two volumes at once,
		// contend on it routinely. Measured, not assumed: four simultaneous
		// downloads holding four *different* tokens produced three successes and
		// one refusal at a zero wait, which is a legitimate download refused
		// because another legitimate download was in the same millisecond.
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $this->name(), $this->wait ) );

		return '1' === (string) $held;
	}

	public function release(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->name() ) );
	}
}
