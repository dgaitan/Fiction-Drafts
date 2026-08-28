<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the product boundary the spec's section 2 draws.
 *
 * Fiction Drafts exports and never restores.  That is not a feature that has
 * been deferred, it is a decision about risk: restoring to a different domain
 * needs serialization-safe search-replace, and a naive str_replace silently
 * corrupts every serialized array by breaking its length prefix.
 *
 * A boundary nobody asserts is a boundary that erodes.  These tests fail the
 * moment someone adds a restore path, so the erosion is loud rather than
 * gradual.  If restore is ever adopted deliberately, deleting this file is the
 * explicit act that records the decision.
 */
final class ExportOnlyBoundaryTest extends TestCase {

	/**
	 * Core options this plugin reads, and why.
	 *
	 * `stylesheet` and `active_plugins` are what make a manifest able to say
	 * what the site was running; `home` is the fallback when `get_home_url()`
	 * is unavailable, which is the case in unit tests. Nothing here is ever
	 * written.
	 *
	 * @var array<int, string>
	 */
	private const CORE_OPTIONS_READ = [ 'stylesheet', 'active_plugins', 'home' ];

	/**
	 * Method-name prefixes that would mean the plugin writes site state back.
	 */
	private const IMPORT_PREFIXES = [ 'restore', 'import', 'unzip', 'extract' ];

	/**
	 * @return array<int, string>
	 */
	private function sourceFiles(): array {
		$root = dirname( __DIR__, 2 ) . '/src';

		$files    = [];
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

		/** @var SplFileInfo $file */
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	public function testThereIsSourceToInspect(): void {
		$this->assertNotEmpty( $this->sourceFiles(), 'the scan must actually find src/ files' );
	}

	public function testNoSourceFileDeclaresARestoreOrImportMethod(): void {
		foreach ( $this->sourceFiles() as $path ) {
			$contents = (string) file_get_contents( $path );

			foreach ( self::IMPORT_PREFIXES as $prefix ) {
				$this->assertSame(
					0,
					preg_match( '/function\s+' . $prefix . '/i', $contents ),
					basename( $path ) . ' declares a ' . $prefix . '* method — Fiction Drafts is export-only'
				);
			}
		}
	}

	/**
	 * The options this plugin writes, and the files allowed to write them.
	 *
	 * The list grows one reviewed line at a time. Sprint 2 added two entries —
	 * `Migrator` for the schema version and `StorageLocator` for the directory
	 * slug — and the test failing first is the review.
	 *
	 * Sprint 7 added `OptionGrantStore`, and this test failing is what made that
	 * a decision rather than an accident: it writes download grants, which is a
	 * *credential* store living in `wp_options`. The review that entry got is
	 * why it stores a SHA-256 of each token and never the token — an archive of
	 * this site contains `wp_options`, so a plaintext grant would be copied,
	 * still valid, into the very backup it authorises.
	 */
	public function testOnlyTheSanctionedFilesWriteOptions(): void {
		$written = [];

		foreach ( $this->sourceFiles() as $path ) {
			$contents = (string) file_get_contents( $path );

			if ( 1 === preg_match( '/\b(?:add_option|update_option)\s*\(/', $contents ) ) {
				$written[] = basename( $path );
			}
		}

		$this->assertSame(
			[ 'OptionGrantStore.php', 'Migrator.php', 'SettingsRepository.php', 'StorageLocator.php' ],
			$written
		);
	}

	/**
	 * Every option this plugin *writes* carries its prefix, and every core
	 * option it *reads* is on a named list.
	 *
	 * Option names are declared as class constants rather than passed as
	 * literals, so the constants are what this reads. An earlier version
	 * scanned the call sites for quoted strings, found none, and passed while
	 * asserting nothing — PHPUnit's risky-test warning is what caught it.
	 *
	 * A second version required the prefix on reads as well, which is the
	 * wrong rule: the manifest has to say which theme and which plugins were
	 * active, and those live in core options by definition. Conflating the two
	 * would have forced the manifest to either lie or reach around the option
	 * API. Reads are checked against an allowlist instead, so a new foreign
	 * option is still a test failure — it just has to be declared here first.
	 */
	public function testEveryOptionNameCarriesThePluginPrefix(): void {
		$found = [];

		foreach ( $this->sourceFiles() as $path ) {
			$contents = (string) file_get_contents( $path );

			// Only files that actually call the option API. BackupJob also
			// declares OPTION_* constants, but those are keys inside the job's
			// own options JSON, not WordPress option names.
			if ( 1 !== preg_match( '/\b(?:add_option|update_option|get_option|delete_option)\s*\(/', $contents ) ) {
				continue;
			}

			preg_match_all( "/const\s+OPTION_[A-Z_]*\s*=\s*'([^']+)'/", $contents, $constants );
			preg_match_all( "/(?:add_option|update_option|delete_option)\(\s*'([^']+)'/", $contents, $written );
			preg_match_all( "/get_option\(\s*'([^']+)'/", $contents, $read );

			foreach ( array_merge( $constants[1], $written[1] ) as $option ) {
				$found[] = $option;

				$this->assertStringStartsWith(
					'fiction_drafts_',
					$option,
					basename( $path ) . ' writes an option outside this plugin'
				);
			}

			foreach ( $read[1] as $option ) {
				$found[] = $option;

				if ( str_starts_with( $option, 'fiction_drafts_' ) ) {
					continue;
				}

				$this->assertContains(
					$option,
					self::CORE_OPTIONS_READ,
					basename( $path ) . ' reads an undeclared core option'
				);
			}
		}

		$this->assertContains( 'fiction_drafts_settings', $found );
		$this->assertContains( 'fiction_drafts_storage_slug', $found );
		$this->assertContains( 'fiction_drafts_db_version', $found );
	}

	public function testNoSourceFileWritesToThePostsOrPostmetaTables(): void {
		foreach ( $this->sourceFiles() as $path ) {
			$contents = (string) file_get_contents( $path );

			$this->assertSame(
				0,
				preg_match( '/\b(?:wp_insert_post|wp_update_post|update_post_meta|wp_insert_attachment)\s*\(/', $contents ),
				basename( $path ) . ' writes post data — a copy tool must only read the site it copies'
			);
		}
	}
}
