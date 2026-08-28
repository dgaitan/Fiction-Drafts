<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Download\DownloadHandler;
use FictionDrafts\Download\OptionGrantStore;
use FictionDrafts\Rest\RestServiceProvider;
use FictionDrafts\Storage\StorageLocator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The rules that hold across the whole download path, not inside one class.
 *
 * A rule like "the archive is never loaded into memory" is not a property of
 * any single function — it is a property of every function on the path, and the
 * only way to keep it true as the code grows is to assert it over the source.
 */
final class DownloadBoundaryTest extends TestCase {

	private const DOWNLOAD_SOURCES = [
		'src/Download/DownloadHandler.php',
		'src/Download/PhpResponseEmitter.php',
		'src/Download/ByteRange.php',
		'src/Rest/DownloadController.php',
	];

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	private static function read( string $relative ): string {
		$contents = file_get_contents( self::root() . '/' . $relative );

		return false === $contents ? '' : $contents;
	}

	/**
	 * A file's source with every comment removed.
	 *
	 * The sweeps below ban calls by name, and the doc comments in this plugin
	 * explain at length *why* those calls are banned — naming them. Searching
	 * the raw file therefore finds the prose that forbids the thing and reports
	 * it as the thing. Tokenising first makes the rule about code, which is
	 * what it was always meant to be, and lets the comments go on saying what
	 * they should.
	 */
	private static function code( string $relative ): string {
		$source = self::read( $relative );

		if ( '' === $source ) {
			return '';
		}

		$out = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			$out .= is_array( $token ) ? $token[1] : $token;
		}

		return $out;
	}

	/**
	 * The control for every source sweep in this class.
	 *
	 * A misspelled path returns '' from `read()`, and a search of '' finds no
	 * violations — so every other test here would pass having read nothing.
	 */
	public function testTheSweepHasSomethingToSweep(): void {
		foreach ( self::DOWNLOAD_SOURCES as $file ) {
			$this->assertNotSame( '', self::read( $file ), $file . ' is empty or missing' );
			$this->assertNotSame( '', self::code( $file ), $file . ' tokenised to nothing' );
		}
	}

	/**
	 * A 2 GB volume through `readfile()` is a fatal under a 128 MB limit, and a
	 * fatal on this endpoint is a download that fails with a blank page.
	 */
	public function testTheDownloadPathNeverLoadsAWholeFile(): void {
		$banned = [ 'readfile(', 'file_get_contents(', 'stream_get_contents(', 'fpassthru(' ];

		foreach ( self::DOWNLOAD_SOURCES as $file ) {
			$source = self::code( $file );

			foreach ( $banned as $call ) {
				$this->assertStringNotContainsString(
					$call,
					$source,
					$file . ' calls ' . $call . ' — the archive must never be held in memory'
				);
			}
		}
	}

	public function testTheChunkSizeIsEightMebibytes(): void {
		$this->assertSame( 8 * 1024 * 1024, DownloadHandler::CHUNK_BYTES );
	}

	public function testTheStreamingLoopReadsAndFlushes(): void {
		$source = self::code( 'src/Download/DownloadHandler.php' );

		$this->assertStringContainsString( 'fread(', $source );
		$this->assertStringContainsString( '->flush()', $source );
	}

	public function testTheEmitterIsTheOnlyPlaceThatTouchesOutput(): void {
		$handler = self::code( 'src/Download/DownloadHandler.php' );

		// `header()`, `echo`, `exit`, and `ob_end_clean()` all live behind the
		// emitter interface. A handler that called them directly would be a
		// handler none of its headers could be asserted about.
		foreach ( [ "\theader(", 'ob_end_clean(', 'set_time_limit(' ] as $call ) {
			$this->assertStringNotContainsString( $call, $handler, $call . ' belongs in the emitter' );
		}
	}

	/**
	 * Spec §10.2, gate 4: the client asks for a job and a volume, never a path.
	 */
	public function testTheHandlerReadsOnlyTheFourExpectedParameters(): void {
		$source = self::code( 'src/Download/DownloadHandler.php' );

		// Whatever it reads out of the query string must be one of these.
		preg_match_all( "/\\\$query\\['([a-z_]+)'\\]/", $source, $found );

		$this->assertNotEmpty( $found[1], 'the control: the handler reads the query at all' );

		foreach ( array_unique( $found[1] ) as $param ) {
			$this->assertContains(
				$param,
				[ 'job', 'volume', 'token', '_wpnonce' ],
				'the handler reads "' . $param . '" from the request — a path parameter is exactly what §10.2 forbids'
			);
		}
	}

	public function testTheHandlerGuardsTheResolvedPath(): void {
		$source = self::code( 'src/Download/DownloadHandler.php' );

		$this->assertStringContainsString( 'PathGuard::isContainedFile(', $source );
	}

	/**
	 * The grant store must not become the credential.
	 *
	 * A backup archive contains `wp_options`. If grants were stored in
	 * plaintext, every archive would ship with working download links to
	 * itself.
	 */
	public function testTheGrantStoreOnlyEverWritesAHash(): void {
		$source = self::code( 'src/Download/OptionGrantStore.php' );

		$this->assertStringContainsString( "hash( 'sha256', \$token )", $source );
		$this->assertStringContainsString( 'hash_equals(', $source );
		// The plaintext token is returned and never handed to the writer.
		$this->assertStringNotContainsString( "'token' => \$token", $source );
	}

	public function testTheTokenComesFromTheCsprng(): void {
		$source = self::code( 'src/Download/OptionGrantStore.php' );

		$this->assertStringContainsString( 'random_bytes(', $source );

		foreach ( [ 'uniqid(', 'wp_rand(', 'mt_rand(', 'wp_generate_password(' ] as $weak ) {
			$this->assertStringNotContainsString( $weak, $source, $weak . ' is not a source of secrets' );
		}
	}

	public function testTheGrantOptionIsCleanedUpOnUninstall(): void {
		$uninstall = self::read( 'uninstall.php' );

		$this->assertStringContainsString( OptionGrantStore::OPTION, $uninstall );
	}

	public function testTheDownloadControllerIsBootedByTheProvider(): void {
		$controllers = ( new ReflectionClass( RestServiceProvider::class ) )->getConstant( 'CONTROLLERS' );

		$this->assertIsArray( $controllers );
		$this->assertContains(
			\FictionDrafts\Rest\DownloadController::class,
			$controllers,
			'a controller bound but not booted is a route that exists in the code and not on the server'
		);
	}

	/**
	 * Spec §10.1 — the storage directory's own guarantees, re-proved here
	 * because Sprint 7 is the sprint that depends on them.
	 */
	public function testTheStorageSuffixIsThirtyTwoHexCharacters(): void {
		fiction_drafts_test_reset_options();

		$slug = ( new StorageLocator() )->slug();

		$this->assertSame( 32, strlen( $slug ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $slug );
	}

	public function testTheStorageSuffixIsStableAcrossReads(): void {
		fiction_drafts_test_reset_options();

		$locator = new StorageLocator();

		// Regenerating would orphan every archive already on disk.
		$this->assertSame( $locator->slug(), ( new StorageLocator() )->slug() );
	}

	public function testTheStorageSlugOptionIsNotAutoloaded(): void {
		fiction_drafts_test_reset_options();

		( new StorageLocator() )->slug();

		$added = fiction_drafts_test_option_calls()['add'];
		$rows  = array_values(
			array_filter(
				$added,
				static fn ( array $call ): bool => StorageLocator::OPTION_SLUG === $call['option']
			)
		);

		$this->assertNotEmpty( $rows, 'the control: the option was written at all' );
		$this->assertFalse( $rows[0]['autoload'] );
	}

	public function testTheClientNeverBuildsADownloadUrl(): void {
		$app    = self::root() . '/assets/app';
		$nested = glob( $app . '/**/*.js' );
		$top    = glob( $app . '/*.js' );
		$files  = array_merge(
			false === $nested ? [] : $nested,
			false === $top ? [] : $top
		);

		$this->assertNotEmpty( $files, 'the control: there are client files to sweep' );

		foreach ( $files as $file ) {
			$source = file_get_contents( $file );

			$this->assertIsString( $source );
			// The server composes the whole URL, nonce included. A client that
			// built one would be a second place that knows the action name and
			// the nonce action.
			$this->assertStringNotContainsString( 'admin-post.php', $source, basename( $file ) );
			$this->assertStringNotContainsString( DownloadHandler::ACTION, $source, basename( $file ) );
		}
	}
}
