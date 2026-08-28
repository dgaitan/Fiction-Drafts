<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Database\DatabaseConnection;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;

/**
 * What an archive says about the site it came from.
 *
 * A backup found on disk a year later has to be self-describing: which site,
 * which WordPress, which PHP, which prefix, which profile, and — the question
 * that matters most when someone is deciding whether to trust it — whether
 * `wp-config.php` is inside.
 *
 * ## Two copies that differ by exactly one array
 *
 * The manifest is written twice, and they are not identical.
 *
 * The copy *inside* the archive is added by ArchiveStage as its second entry,
 * before any volume has been sealed, so it cannot contain volume checksums —
 * a file cannot carry its own hash.  The copy *beside* the volumes is written
 * by FinalizeStage once every volume is closed and hashed, and carries the
 * `volumes` ledger as well.
 *
 * That asymmetry is stated rather than smoothed over: `volumes` is always
 * present as a key, and is an empty array in the inner copy.  A reader that
 * finds it empty knows it is holding the inner copy, not a broken one.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * -- WP_Filesystem is not initialised inside an Action Scheduler worker, and
 * these paths are inside the plugin's own storage root, never user input.
 */
final class Manifest {

	public const FILENAME = 'manifest.json';

	/**
	 * Every key a manifest carries, in a fixed order.
	 *
	 * Declared as a constant rather than implied by build(): the acceptance
	 * criterion is that the key set is exactly this, and a test that reads the
	 * constant would pass whatever build() happened to produce.  The test
	 * compares build()'s keys against this list in both directions.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [
		'schema',
		'site_url',
		'home_url',
		'wp_version',
		'php_version',
		'mysql_version',
		'table_prefix',
		'multisite',
		'active_theme',
		'active_plugins',
		'profile',
		'profile_areas',
		'includes_wp_config',
		'excludes_transients',
		'file_count',
		'total_bytes',
		'skipped_symlinks',
		'created_at',
		'volumes',
	];

	/**
	 * Bumped when the shape changes, so a future reader can tell.
	 */
	public const SCHEMA = 1;

	public function __construct( private readonly ?DatabaseConnection $database = null ) {}

	/**
	 * Build the manifest for one job.
	 *
	 * @param  array<int, ArchiveVolume> $volumes Sealed volumes, empty for the in-archive copy.
	 * @return array<string, mixed>
	 */
	public function build(
		BackupJob $job,
		int $fileCount,
		int $totalBytes,
		int $skippedSymlinks = 0,
		array $volumes = []
	): array {
		return [
			'schema'              => self::SCHEMA,
			'site_url'            => self::siteUrl(),
			'home_url'            => self::homeUrl(),
			'wp_version'          => (string) get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'mysql_version'       => $this->mysqlVersion(),
			'table_prefix'        => $this->tablePrefix(),
			'multisite'           => is_multisite(),
			'active_theme'        => self::activeTheme(),
			'active_plugins'      => self::activePlugins(),
			'profile'             => $job->profile->value,
			// The profile name alone does not say what a Custom job copied,
			// and Custom is the profile most likely to surprise someone a year
			// later.  Recording the resolved answers means the manifest is
			// readable without re-deriving anything.
			'profile_areas'       => [
				'database' => $job->includesDatabase(),
				'core'     => $job->includesCore(),
				'uploads'  => $job->includesUploads(),
			],
			'includes_wp_config'  => $job->includesWpConfig(),
			'excludes_transients' => false !== $job->option( BackupJob::OPTION_EXCLUDE_TRANSIENTS, true ),
			'file_count'          => $fileCount,
			'total_bytes'         => $totalBytes,
			'skipped_symlinks'    => $skippedSymlinks,
			// The job's creation time, not the moment this was written.  The
			// manifest describes a backup, and a backup is dated by when it was
			// asked for — otherwise the inner and sidecar copies would carry
			// two different dates for one archive.
			'created_at'          => $job->createdAt ?? gmdate( 'Y-m-d H:i:s' ),
			'volumes'             => array_map(
				static fn ( ArchiveVolume $volume ): array => $volume->toArray(),
				array_values( $volumes )
			),
		];
	}

	/**
	 * Write a manifest, pretty-printed so a human opening it can read it.
	 *
	 * @param array<string, mixed> $manifest Built manifest.
	 */
	public static function write( string $path, array $manifest ): bool {
		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return false;
		}

		// Suppressed deliberately: a caller-supplied path that turns out to be
		// unwritable is this function's return value, not a warning in the
		// site's error log. FinalizeStage reads the file back and fails the job
		// with a sentence when it is not there.
		return false !== @file_put_contents( $path, $json . "\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * @return array<string, mixed>|null Null when absent or unreadable.
	 */
	public static function read( string $path ): ?array {
		if ( ! is_file( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The MySQL server's own version string.
	 *
	 * Null rather than a guess when there is no connection: a manifest that
	 * says "unknown" is honest, and one that says "5.7" because that was a
	 * reasonable default is a trap for whoever reads it later.
	 */
	private function mysqlVersion(): ?string {
		if ( null === $this->database ) {
			return null;
		}

		return $this->database->var( 'SELECT VERSION()' );
	}

	private function tablePrefix(): string {
		if ( null !== $this->database ) {
			return $this->database->prefix();
		}

		global $wpdb;

		return isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
	}

	/**
	 * The active theme's directory, read from the option rather than from
	 * wp_get_theme().
	 *
	 * The option is a string and is what a restore would look for; the theme
	 * object's display name is prose that changes between versions of the same
	 * theme.  Houzez Child 1.0 and Houzez Child 1.4 are the same directory.
	 */
	private static function activeTheme(): string {
		$stylesheet = get_option( 'stylesheet', '' );

		return is_string( $stylesheet ) ? $stylesheet : '';
	}

	/**
	 * @return array<int, string>
	 */
	private static function activePlugins(): array {
		$active = get_option( 'active_plugins', [] );

		if ( ! is_array( $active ) ) {
			return [];
		}

		return array_values( array_filter( $active, 'is_string' ) );
	}

	private static function siteUrl(): string {
		return function_exists( 'get_site_url' ) ? (string) get_site_url() : '';
	}

	/**
	 * home_url and site_url differ on every site where WordPress lives in a
	 * subdirectory, and telling them apart later is the difference between a
	 * copy that can be stood up and one that half-works.
	 */
	private static function homeUrl(): string {
		if ( function_exists( 'get_home_url' ) ) {
			return (string) get_home_url();
		}

		$home = get_option( 'home', '' );

		return is_string( $home ) ? $home : '';
	}
}
