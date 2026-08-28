<?php

declare( strict_types=1 );

namespace FictionDrafts\Files;

use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\ExclusionSet;
use FictionDrafts\Domain\Settings;

/**
 * What one job's file scan covers: where it starts, and what it refuses.
 *
 * ## Why there are roots at all, and not just exclusions
 *
 * A `CUSTOM` job can ask for uploads without core.  That cannot be written as
 * an exclusion list, because the thing it would have to exclude is "every root
 * file", and root files are not enumerable — a site can have anything at its
 * top level.  Selecting the roots to walk answers it directly: the whole site
 * when the job includes core, `wp-content/uploads` alone when it does not.
 *
 * Everything else stays an exclusion, because exclusions compose and roots do
 * not.
 */
final class ScanScope {

	public const FILTER_EXCLUSIONS = 'fiction_drafts/exclusions';

	private const UPLOADS = 'wp-content/uploads';

	/**
	 * @param array<int, string> $roots Root-relative directories to walk; '' means the site root.
	 */
	private function __construct(
		private readonly array $roots,
		private readonly ExclusionSet $exclusions
	) {}

	/**
	 * Resolve the scope for a job, once.
	 *
	 * Built once per stage run rather than once per directory: the exclusion
	 * set compiles a regular expression per pattern, and a 100k-file site has
	 * tens of thousands of directories.
	 */
	public static function forJob( BackupJob $job, ?Settings $settings = null ): self {
		$exclusions = $job->profile->defaultExclusions();

		// The profile answers for the four presets; the job answers for Custom.
		// Deriving the uploads rule from the job rather than the profile is
		// what lets a Custom job opt uploads back in.
		$exclusions = $job->includesUploads()
			? $exclusions->without( self::UPLOADS . '/**' )
			: $exclusions->with( self::UPLOADS . '/**' );

		// The one pattern an administrator can lift, per job, never per
		// profile and never stickily — see spec section 6.3.
		if ( $job->includesWpConfig() ) {
			$exclusions = $exclusions->without( 'wp-config.php' );
		}

		if ( null !== $settings ) {
			$exclusions = $exclusions->with( ...$settings->exclusions()->patterns() );
		}

		/** @var array<int, mixed> $filtered */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook name is self::FILTER_EXCLUSIONS, 'fiction_drafts/exclusions'; the sniff cannot resolve a constant.
		$filtered = apply_filters( self::FILTER_EXCLUSIONS, $exclusions->patterns(), $job );

		if ( is_array( $filtered ) ) {
			$exclusions = new ExclusionSet( array_values( array_filter( $filtered, 'is_string' ) ) );
		}

		return new self( self::rootsFor( $job ), $exclusions );
	}

	/**
	 * @return array<int, string>
	 */
	public function roots(): array {
		return $this->roots;
	}

	public function exclusions(): ExclusionSet {
		return $this->exclusions;
	}

	/**
	 * Which directories this job's walk starts from.
	 *
	 * '' is the site root and subsumes everything, so it is never combined
	 * with a narrower root — walking both would visit uploads twice and put
	 * every media file in the archive two times.
	 *
	 * @return array<int, string>
	 */
	private static function rootsFor( BackupJob $job ): array {
		if ( $job->includesCore() ) {
			return [ '' ];
		}

		return $job->includesUploads() ? [ self::UPLOADS ] : [];
	}
}
