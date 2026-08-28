<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

/**
 * Where download grants are kept between issue and use.
 *
 * An interface for the reason every other store in this plugin is one: the
 * single-use guarantee is the security property of the whole sprint, and it has
 * to be provable against a controllable clock rather than by waiting five
 * minutes.
 */
interface GrantStore {

	/**
	 * Mint a grant and return the secret — the only time it exists in plaintext.
	 *
	 * @return string The token to put in the URL.
	 */
	public function issue( string $jobUuid, int $sequence, int $userId ): string;

	/**
	 * Claim a grant, atomically, exactly once.
	 *
	 * Returns the grant to the first caller and null to every caller after,
	 * including a caller running in another PHP process a microsecond later.
	 * Expiry, user binding, and job/volume binding are all the caller's answer
	 * to check via `DownloadGrant::authorises()`; what this method guarantees
	 * is that the grant is gone.
	 */
	public function consume( string $token ): ?DownloadGrant;

	/**
	 * Drop every stored grant.  Used by uninstall and by tests.
	 */
	public function flush(): void;
}
