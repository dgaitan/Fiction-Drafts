<?php

declare( strict_types=1 );

namespace FictionDrafts\Storage;

/**
 * Where archives live, and what guards them.
 *
 * The directory name carries 32 random hex characters generated once at
 * activation.  On Apache the `.htaccess` written here denies direct access;
 * on nginx — which this development instance runs — `.htaccess` is not read
 * at all, so the real controls are the unguessable name and the fact that
 * archives are only ever served by PHP through a capability-gated endpoint.
 * The readme says so plainly rather than implying protection the server does
 * not provide.
 */
final class StorageLocator {

	public const OPTION_SLUG = 'fiction_drafts_storage_slug';

	private const INDEX_PHP = "<?php\n// Silence is golden.\n";

	private const HTACCESS = "# Apache only. nginx does not read this file.\nRequire all denied\n";

	private const WEB_CONFIG = '<?xml version="1.0" encoding="UTF-8"?>
<configuration>
	<system.webServer>
		<authorization>
			<deny users="*" />
		</authorization>
	</system.webServer>
</configuration>
';

	private ?string $baseDir = null;

	/**
	 * @param string|null $root Explicit storage root, overriding every other source.
	 */
	public function __construct( ?string $root = null ) {
		$this->baseDir = null === $root ? null : untrailingslashit( $root );
	}

	/**
	 * The storage root, created lazily by ensure().
	 *
	 * FICTION_DRAFTS_STORAGE_DIR relocates it entirely — the strongest option,
	 * because a path outside the document root cannot be requested at all.
	 */
	public function baseDir(): string {
		if ( null !== $this->baseDir ) {
			return $this->baseDir;
		}

		if ( defined( 'FICTION_DRAFTS_STORAGE_DIR' ) ) {
			$this->baseDir = untrailingslashit( (string) constant( 'FICTION_DRAFTS_STORAGE_DIR' ) );

			return $this->baseDir;
		}

		$this->baseDir = untrailingslashit( WP_CONTENT_DIR ) . '/fiction-drafts-' . $this->slug();

		return $this->baseDir;
	}

	/**
	 * The 32-hex suffix, generated once and remembered.
	 *
	 * Regenerating it would orphan every existing archive, so it is written
	 * once and only once.
	 */
	public function slug(): string {
		$stored = get_option( self::OPTION_SLUG, null );

		if ( is_string( $stored ) && 32 === strlen( $stored ) ) {
			return $stored;
		}

		$slug = strtolower( bin2hex( random_bytes( 16 ) ) );

		// `add_option()` is the atomic half — it refuses when the row already
		// exists, so two requests racing here cannot both write. The half that
		// was missing is honouring that refusal: whoever loses must adopt the
		// winner's slug, not carry on with the one it generated. Otherwise two
		// concurrent activations resolve two different storage directories,
		// and every archive written to the loser's is invisible to the winner.
		if ( add_option( self::OPTION_SLUG, $slug, '', false ) ) {
			return $slug;
		}

		// Past the object cache: the miss above may have populated `notoptions`
		// in this worker, which would make the row the winner just wrote
		// invisible to the re-read.
		wp_cache_delete( self::OPTION_SLUG, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$winner = get_option( self::OPTION_SLUG, null );

		return ( is_string( $winner ) && 32 === strlen( $winner ) ) ? $winner : $slug;
	}

	/**
	 * Create the storage root and its guard files if they are missing.
	 */
	public function ensure(): bool {
		$base = $this->baseDir();

		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return false;
		}

		$this->writeGuard( $base . '/index.php', self::INDEX_PHP );
		$this->writeGuard( $base . '/.htaccess', self::HTACCESS );
		$this->writeGuard( $base . '/web.config', self::WEB_CONFIG );

		return true;
	}

	/**
	 * A job's working directory — removed when the job finalizes.
	 */
	public function workingDir( string $uuid ): string {
		return $this->baseDir() . '/' . sanitize_key( $uuid );
	}

	public function isWritable(): bool {
		return is_dir( $this->baseDir() ) && wp_is_writable( $this->baseDir() );
	}

	/**
	 * Remove a directory and everything under it, without following symlinks.
	 *
	 * Used by cancel, finalize, and uninstall.  Refuses any path that is not
	 * inside the storage root, so a bad caller cannot turn this into a
	 * general-purpose deleter.
	 */
	public function removeDirectory( string $path ): bool {
		$base   = realpath( $this->baseDir() );
		$target = realpath( $path );

		if ( false === $base || false === $target ) {
			return false;
		}

		if ( $target !== $base && ! str_starts_with( $target, $base . '/' ) ) {
			return false;
		}

		return self::deleteTree( $target );
	}

	private static function deleteTree( string $path ): bool {
		if ( is_link( $path ) || is_file( $path ) ) {
			return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() returns void, and this reports whether the deletion succeeded.
		}

		if ( ! is_dir( $path ) ) {
			return true;
		}

		$entries = scandir( $path );

		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			self::deleteTree( $path . '/' . $entry );
		}

		return rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem is not initialised during activation or uninstall.
	}

	/**
	 * Write a guard file, if it is missing and the directory will take it.
	 *
	 * The writability check is not belt-and-braces. Without it, a storage
	 * directory whose permissions have been tightened makes this emit a PHP
	 * warning on every call — including from inside Preflight, whose whole
	 * purpose is to replace exactly that warning with a sentence an
	 * administrator can act on.
	 */
	private function writeGuard( string $path, string $contents ): void {
		if ( file_exists( $path ) || ! wp_is_writable( dirname( $path ) ) ) {
			return;
		}

		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- runs at activation, before WP_Filesystem is initialised.
	}
}
