<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

use FictionDrafts\Backup\JobLock;

/**
 * Grants in one non-autoloaded option, hashed, swept, and claimed under a lock.
 *
 * ## The store must not be the credential
 *
 * This is the decision the rest of the class exists to serve, and it is the one
 * that is easy to get wrong by doing the obvious thing.  **This plugin's whole
 * job is to put `wp_options` into a downloadable archive.**  A grant stored in
 * plaintext would therefore be copied, verbatim and still valid, into the very
 * backup it authorises — every archive would ship with live download
 * credentials for itself, and anyone who ever obtained one archive would hold
 * working links to the others.  The same applies to any database dump the site
 * owner takes by any other means, and to any SQL-injection read anywhere else
 * on the site.
 *
 * So the option holds `hash('sha256', $token)` and never the token.  The
 * plaintext exists for exactly the length of `issue()`, travels in the URL, and
 * is never written down.  A store that leaks now leaks a list of hashes of
 * secrets that expired five minutes ago.
 *
 * ## Why an option and not a transient
 *
 * A transient has a TTL, which is the appealing part, and no atomicity, which
 * is the disqualifying one.  `get_transient()` then `delete_transient()` is
 * read-then-write across processes: two replays of the same URL, arriving
 * together, both read the record before either deletes it, and "single-use"
 * quietly becomes "twice".  Expiry is arithmetic — five lines — while atomicity
 * is not something that can be added afterwards.
 *
 * So: one option, and `consume()` reads, decides, and writes while holding the
 * same kind of MySQL named lock `StageRunner` takes for a step.  The lock is
 * released when the connection closes, so a fatal between claim and release
 * cannot wedge downloads.
 *
 * ## Why one option rather than one per grant
 *
 * A row per grant has no natural sweep, and a site whose administrator clicks
 * Download twice a week accumulates option rows for the lifetime of the
 * install.  One row holds a map, and every write is an opportunity to drop what
 * has expired — so the sweep is not a cron job that might not run, it is a
 * property of using the thing at all.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 * -- Options only; the lock lives in JobLock, which documents its own use.
 */
final class OptionGrantStore implements GrantStore {

	public const OPTION = 'fiction_drafts_download_grants';

	/**
	 * A ceiling on stored grants, so that a script hammering the issue route
	 * cannot grow one option row without bound.  Expired records go first;
	 * only if the store is still over the cap are the oldest live ones dropped,
	 * which costs an administrator a re-click and costs an attacker the ability
	 * to fill a table.
	 */
	private const MAX_GRANTS = 50;

	public function __construct(
		private readonly ?JobLock $lock = null,
		private readonly ?\Closure $clock = null
	) {}

	public function issue( string $jobUuid, int $sequence, int $userId ): string {
		// 32 bytes from the CSPRNG. `wp_generate_password()` and `uniqid()` are
		// both wrong here for different reasons — one is tuned for typing by a
		// human, the other is the system clock wearing a disguise.
		//
		// `random_bytes()` throws when the platform has no usable entropy
		// source. Letting that propagate as a 500 is right; what must not happen
		// is a catch that produces a short or empty token, so there is no catch.
		$token = bin2hex( random_bytes( 32 ) );

		$grant = new DownloadGrant(
			self::fingerprint( $token ),
			$jobUuid,
			$sequence,
			$userId,
			$this->now()
		);

		$stored = $this->mutate(
			function ( array $grants ) use ( $grant ): array {
				$grants[ $grant->hash ] = $grant->toArray();

				return $grants;
			}
		);

		// A grant that was not written is not a grant. Returning the token
		// anyway would hand the administrator a link that refuses itself five
		// seconds later, with a message about the link having been used.
		return $stored ? $token : '';
	}

	public function consume( string $token ): ?DownloadGrant {
		$fingerprint = self::fingerprint( $token );
		$claimed     = null;

		$this->mutate(
			function ( array $grants ) use ( $fingerprint, &$claimed ): array {
				$grant = DownloadGrant::fromArray( $grants[ $fingerprint ] ?? null );

				// Removed whether or not it turned out to be usable. An expired
				// or malformed record that stayed put would be re-examined on
				// every subsequent request for as long as it sat there.
				unset( $grants[ $fingerprint ] );

				if ( null === $grant ) {
					return $grants;
				}

				// The lookup above is by hash, so the secret itself is never
				// compared. This compares the two hashes anyway, in constant
				// time: it costs nothing, and it means the claim does not rest
				// on an assumption about how PHP's hash table compares keys.
				if ( ! hash_equals( $grant->hash, $fingerprint ) ) {
					return $grants;
				}

				$claimed = $grant;

				return $grants;
			}
		);

		if ( null === $claimed ) {
			return null;
		}

		// Expiry is decided here rather than in the callback so that the
		// record is dropped either way — an expired grant is claimed and then
		// refused, which is the same outcome as claiming it and using it as far
		// as the store is concerned.
		return $claimed->hasExpired( $this->now() ) ? null : $claimed;
	}

	public function flush(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Read, transform, and write the store while holding the lock.
	 *
	 * The lock is best-effort by design. Failing to take it means another
	 * process is inside this function right now; proceeding anyway is how the
	 * lost update the class comment describes actually happens, so a failed
	 * acquire abandons the mutation. For `issue()` that costs a click. For
	 * `consume()` it costs a refused download — which is the right way to be
	 * wrong about whether a token was already spent.
	 *
	 * @param  \Closure(array<string, mixed>): array<string, mixed> $change Transform.
	 * @return bool Whether the mutation was applied.
	 */
	private function mutate( \Closure $change ): bool {
		if ( null !== $this->lock && ! $this->lock->acquire() ) {
			return false;
		}

		try {
			$grants = $this->read();
			$grants = $change( $grants );

			$this->write( $this->sweep( $grants ) );
		} finally {
			$this->lock?->release();
		}

		return true;
	}

	/**
	 * The stored grants, read past the object cache.
	 *
	 * Non-autoloaded does not mean uncached. On a site running Redis or
	 * Memcached, `get_option()` is answered from `wp_cache_get()`, and the copy
	 * in this worker's cache may predate the lock this method is called under.
	 * The lock then serialises a critical section whose *read* is stale: worker
	 * B takes the lock, sees a token worker A already claimed, and honours it.
	 * A single-use token used twice — on production only, because a development
	 * machine has no object cache, one worker, and no concurrency, so the lock
	 * looks like it is doing the job.
	 *
	 * Deleting the cache entry inside the lock makes the read a real read.
	 * `notoptions` goes too: it is where WordPress remembers that an option does
	 * not exist, and a stale entry there makes a freshly written store invisible.
	 *
	 * @return array<string, mixed>
	 */
	private function read(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * @param array<string, mixed> $grants Records to persist.
	 */
	private function write( array $grants ): void {
		if ( [] === $grants ) {
			delete_option( self::OPTION );

			return;
		}

		update_option( self::OPTION, $grants, false );
	}

	/**
	 * Drop what has expired, then what is oldest beyond the cap.
	 *
	 * @param  array<string, mixed> $grants Records as stored.
	 * @return array<string, mixed>
	 */
	private function sweep( array $grants ): array {
		$now  = $this->now();
		$live = [];

		foreach ( $grants as $hash => $row ) {
			$grant = DownloadGrant::fromArray( $row );

			// A record this version cannot parse is dropped rather than kept.
			// It cannot authorise anything — `fromArray()` is the only way in —
			// so keeping it would only preserve a row nothing can ever use.
			if ( null === $grant || $grant->hasExpired( $now ) ) {
				continue;
			}

			$live[ (string) $hash ] = $row;
		}

		if ( count( $live ) <= self::MAX_GRANTS ) {
			return $live;
		}

		uasort(
			$live,
			static function ( mixed $left, mixed $right ): int {
				$a = is_array( $left ) && is_int( $left['issued_at'] ?? null ) ? $left['issued_at'] : 0;
				$b = is_array( $right ) && is_int( $right['issued_at'] ?? null ) ? $right['issued_at'] : 0;

				return $b <=> $a;
			}
		);

		return array_slice( $live, 0, self::MAX_GRANTS, true );
	}

	/**
	 * SHA-256 of the token.  Not a password hash on purpose: the input is 256
	 * bits of CSPRNG output with a five-minute life, so there is nothing for a
	 * work factor to slow down, and a slow hash on the download path would be a
	 * denial-of-service handle instead of a defence.
	 */
	private static function fingerprint( string $token ): string {
		return hash( 'sha256', $token );
	}

	private function now(): int {
		return null === $this->clock ? time() : (int) ( $this->clock )();
	}
}
