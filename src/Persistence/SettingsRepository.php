<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

use FictionDrafts\Domain\Settings;

/**
 * Reads and writes the plugin's single settings option.
 *
 * The option is never autoloaded.  Fiction Drafts is an admin tool that runs
 * a handful of times a month; making every front-end request on the site pay
 * to load an exclusion list it will never consult is a cost with no benefit.
 */
final class SettingsRepository {

	public const OPTION_NAME = 'fiction_drafts_settings';

	private ?Settings $cached = null;

	/**
	 * Current settings, falling back to defaults when nothing is stored.
	 *
	 * Cached for the life of the request.  A single job step can consult the
	 * exclusion list thousands of times while walking the filesystem.
	 */
	public function get(): Settings {
		if ( null !== $this->cached ) {
			return $this->cached;
		}

		$stored = get_option( self::OPTION_NAME, null );

		$this->cached = is_array( $stored ) ? Settings::fromArray( $stored ) : Settings::defaults();

		return $this->cached;
	}

	/**
	 * Persist settings with `autoload` off, whether or not the row exists yet.
	 *
	 * The branch is not redundant.  On WordPress 6.6 and later update_option()
	 * honours a non-null $autoload for a row that already exists, but on 6.4
	 * and 6.5 — still inside this plugin's declared "Requires at least" range —
	 * it only applied $autoload when creating the row.  Calling add_option()
	 * explicitly for the absent case is correct across the whole range.
	 */
	public function save( Settings $settings ): bool {
		$payload = $settings->toArray();
		$exists  = null !== get_option( self::OPTION_NAME, null );

		$saved = $exists
			? update_option( self::OPTION_NAME, $payload, false )
			: add_option( self::OPTION_NAME, $payload, '', false );

		// On failure the cache is dropped rather than filled, so the next
		// read reports what is actually stored instead of what we wanted.
		$this->cached = $saved ? $settings : null;

		return $saved;
	}

	/**
	 * Forget the per-request cache.  Tests and long-running CLI processes need
	 * this; a normal web request does not.
	 */
	public function flush(): void {
		$this->cached = null;
	}
}
