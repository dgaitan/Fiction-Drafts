<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

/**
 * Permission to download one volume, once, soon.
 *
 * Named a grant rather than a token because the secret is not the interesting
 * part — what it *authorises* is.  A grant names exactly one job, exactly one
 * volume sequence, and exactly one user, and it stops being true five minutes
 * after it was issued.  A grant for job A volume 1 cannot fetch job A volume 2,
 * and cannot be presented by anybody but the administrator it was issued to.
 *
 * The secret itself never lives in an instance of this class after issue: the
 * store keeps a hash (see `GrantStore`), and this object carries that hash.
 */
final class DownloadGrant {

	/**
	 * Five minutes.
	 *
	 * Long enough to click a link and for a browser to start the transfer,
	 * short enough that a URL captured in history, a proxy log, or an
	 * over-the-shoulder screenshot is dead before anyone reads it. The window
	 * bounds the *start* of a download, never its duration — the grant is
	 * consumed on the first byte, so a two-hour transfer of a 40 GB archive is
	 * unaffected by a five-minute expiry.
	 */
	public const TTL_SECONDS = 300;

	public function __construct(
		public readonly string $hash,
		public readonly string $jobUuid,
		public readonly int $sequence,
		public readonly int $userId,
		public readonly int $issuedAt
	) {}

	public function expiresAt(): int {
		return $this->issuedAt + self::TTL_SECONDS;
	}

	public function hasExpired( int $now ): bool {
		return $now >= $this->expiresAt();
	}

	/**
	 * Does this grant authorise what is being asked for?
	 *
	 * Every field is compared, not just the user.  A grant that checked only
	 * the presenter would let an administrator who was legitimately given a
	 * link to one volume walk the whole ledger with it — which is not an
	 * escalation on a single-site install, but is exactly the property that
	 * makes "single-use" mean something.
	 */
	public function authorises( string $jobUuid, int $sequence, int $userId ): bool {
		return hash_equals( $this->jobUuid, $jobUuid )
			&& $this->sequence === $sequence
			&& $this->userId === $userId;
	}

	/**
	 * @return array<string, int|string>
	 */
	public function toArray(): array {
		return [
			'hash'      => $this->hash,
			'job'       => $this->jobUuid,
			'sequence'  => $this->sequence,
			'user'      => $this->userId,
			'issued_at' => $this->issuedAt,
		];
	}

	/**
	 * Rebuild from a stored row, or null when the row is not one.
	 *
	 * The store is an option, and an option is input — anything with database
	 * access can put a shape in there that this code never wrote.  Every field
	 * is checked for type rather than cast, because casting `['user' => []]` to
	 * an int produces user 1 on some paths, and user 1 is usually the site
	 * owner.
	 *
	 * @param mixed $row A single decoded record.
	 */
	public static function fromArray( mixed $row ): ?self {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$hash     = $row['hash'] ?? null;
		$job      = $row['job'] ?? null;
		$sequence = $row['sequence'] ?? null;
		$user     = $row['user'] ?? null;
		$issued   = $row['issued_at'] ?? null;

		if ( ! is_string( $hash ) || ! is_string( $job ) ) {
			return null;
		}

		if ( ! is_int( $sequence ) || ! is_int( $user ) || ! is_int( $issued ) ) {
			return null;
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return null;
		}

		return new self( $hash, $job, $sequence, $user, $issued );
	}
}
