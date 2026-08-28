<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * What areas of the site a backup copies.
 *
 * A profile describes *areas* only.  Whether `wp-config.php` is included is a
 * per-job decision carried in the job's options, never a property of a
 * profile — see the spec, section 6.3.  There is deliberately no
 * includesWpConfig() method here.
 *
 * The spec's section 6.1 table, reproduced so this file can be checked against
 * it without opening the spec:
 *
 *   Profile         | DB | core | plugins | themes | uploads | root files
 *   ----------------|----|------|---------|--------|---------|-----------
 *   Full            | ✅ | ✅   | ✅      | ✅     | ✅      | ✅
 *   DatabaseOnly    | ✅ | —    | —       | —      | —       | —
 *   FilesOnly       | —  | ✅   | ✅      | ✅     | ✅      | ✅
 *   FilesNoMedia    | ✅ | ✅   | ✅      | ✅     | ❌      | ✅
 *   Custom          | opt-in in every column
 *
 * The four file columns carry identical values in every row, so one predicate
 * — includesCore() — answers for all four.  Splitting it into
 * includesPlugins() and includesThemes() would create three places that can
 * disagree about one fact.
 */
enum BackupProfile: string {

	case Full         = 'full';
	case DatabaseOnly = 'database_only';
	case FilesOnly    = 'files_only';
	case FilesNoMedia = 'files_no_media';
	case Custom       = 'custom';

	/**
	 * Paths excluded from every profile, because copying them is either
	 * dangerous or useless — spec section 6.2.
	 *
	 * Each pattern that can occur both at the WordPress root and nested inside
	 * it is listed twice.  `**` matches across segments but still requires a
	 * segment to consume, so `** /node_modules/**` alone would miss a
	 * root-level `node_modules/`.
	 *
	 * The plugin's own storage directory is NOT here.  Its name contains 32
	 * random hex characters generated at activation, so it is not knowable
	 * statically; FileWalker applies it at runtime before any other filter.
	 *
	 * @var array<int, string>
	 */
	private const ALWAYS_EXCLUDED = [
		// Credentials and salts.  The one pattern an admin can lift per job,
		// via ExclusionSet::without() — see spec section 6.3.
		'wp-config.php',

		// A storage directory left behind by an earlier install of this plugin.
		// The *current* one is excluded at runtime by absolute path, because
		// its 32 hex characters are not knowable statically — but a directory
		// from a previous install, or from before the slug was regenerated,
		// has no runtime rule watching it, and it is full of old archives.
		// Found by a live run that archived a decoy storage directory.
		'wp-content/fiction-drafts-*/**',

		// Regenerable caches and half-finished core updates.
		'wp-content/cache/**',
		'wp-content/upgrade/**',

		// Other backup plugins' output.  Copying it doubles the archive.
		'wp-content/uploads/backwpup*',
		'wp-content/uploads/backwpup*/**',
		'ai1wm-backups/**',
		'**/ai1wm-backups/**',

		// Build and VCS directories.
		'node_modules/**',
		'**/node_modules/**',
		'.git/**',
		'**/.git/**',
		'.svn/**',
		'**/.svn/**',

		// Noise.  `._*` are macOS AppleDouble resource forks, which a site
		// developed on a Mac accumulates in every directory.
		'.DS_Store',
		'**/.DS_Store',
		'._*',
		'**/._*',
		'*.log',
		'**/*.log',
	];

	/**
	 * Untranslated identifier for logs and the REST surface.
	 *
	 * Sprint 6 adds the translated, user-facing label in the admin layer.
	 */
	public function slug(): string {
		return $this->value;
	}

	/**
	 * Does this profile export the database?
	 *
	 * Custom answers false: every one of its columns is opt-in, so the profile
	 * alone cannot say.  The per-area opt-ins live in the job's options JSON
	 * and are resolved by Stage::appliesTo().  A default-deny answer means a
	 * half-configured custom job copies too little, which is visible; the
	 * other default copies too much, which is not.
	 */
	public function includesDatabase(): bool {
		return match ( $this ) {
			self::Full, self::DatabaseOnly, self::FilesNoMedia => true,
			self::FilesOnly, self::Custom                      => false,
		};
	}

	/**
	 * Does this profile export site files other than uploads?
	 *
	 * That means WordPress core, wp-admin, wp-includes, plugins, themes, and
	 * other root files — the four file columns of the section 6.1 table that
	 * always move together.
	 */
	public function includesCore(): bool {
		return match ( $this ) {
			self::Full, self::FilesOnly, self::FilesNoMedia => true,
			self::DatabaseOnly, self::Custom               => false,
		};
	}

	/**
	 * Does this profile export `wp-content/uploads`?
	 */
	public function includesUploads(): bool {
		return match ( $this ) {
			self::Full, self::FilesOnly                          => true,
			self::DatabaseOnly, self::FilesNoMedia, self::Custom => false,
		};
	}

	/**
	 * The exclusions a job starts with under this profile.
	 *
	 * The media rule is derived rather than enumerated: a profile that does
	 * not include uploads excludes them.  One rule, so a future profile cannot
	 * claim not to include uploads and then ship them anyway.
	 */
	public function defaultExclusions(): ExclusionSet {
		$exclusions = new ExclusionSet( self::ALWAYS_EXCLUDED );

		if ( ! $this->includesUploads() ) {
			$exclusions = $exclusions->with( 'wp-content/uploads/**' );
		}

		return $exclusions;
	}
}
