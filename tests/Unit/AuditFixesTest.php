<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Admin\AdminPage;
use FictionDrafts\Archive\EntryFootprint;
use FictionDrafts\Archive\ZipWriter;
use FictionDrafts\Backup\Manifest;
use FictionDrafts\Rest\AbstractController;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * The audit's findings, each as a test that fails without its fix.
 *
 * Grouped in one class on purpose: these are not a feature, they are a list of
 * things that were wrong, and keeping them together makes the list legible.
 * Every one of them was reachable in production and unreachable from the suite
 * as it stood.
 */
final class AuditFixesTest extends TestCase {

	private TempTree $tree;

	protected function setUp(): void {
		fiction_drafts_test_reset_options();

		$this->tree = new TempTree();
	}

	protected function tearDown(): void {
		$this->tree->remove();

		$GLOBALS['fiction_drafts_test_multisite'] = false;
	}

	// ------------------------------------------------- the unbounded sidecar

	public function testAnOversizedSidecarIsRefusedRatherThanRead(): void {
		$path = $this->tree->path( 'manifest.json' );

		// Valid JSON, and far past anything a manifest could be. Reading it
		// whole peaked at 127 MiB for a single call, on a route that reads one
		// per backup.
		$this->tree->file(
			'manifest.json',
			(string) wp_json_encode( [ 'active_plugins' => array_fill( 0, 20000, str_repeat( 'a', 120 ) ) ] )
		);

		$this->assertGreaterThan( Manifest::MAX_READ_BYTES, (int) filesize( $path ) );
		$this->assertNull( Manifest::read( $path ) );
	}

	/**
	 * The control. Without it, a `read()` that returned null unconditionally
	 * would pass the test above.
	 */
	public function testARealSidecarStillReads(): void {
		$this->tree->file(
			'manifest.json',
			(string) wp_json_encode(
				[
					'schema'   => 1,
					'site_url' => 'https://example.test',
				]
			)
		);

		$manifest = Manifest::read( $this->tree->path( 'manifest.json' ) );

		$this->assertIsArray( $manifest );
		$this->assertSame( 'https://example.test', $manifest['site_url'] );
	}

	public function testTheCeilingIsMeasuredOnBytesReadNotOnAPriorStat(): void {
		// Exactly at the ceiling is allowed; one byte over is not. The read
		// asks for one byte more than the ceiling precisely so this boundary
		// cannot be raced by a file that grows after a filesize() call.
		$this->tree->file( 'at.json', str_pad( '{"schema":1', Manifest::MAX_READ_BYTES - 1, ' ' ) . '}' );
		$this->tree->file( 'over.json', str_pad( '{"schema":1', Manifest::MAX_READ_BYTES, ' ' ) . '}' );

		$this->assertSame( Manifest::MAX_READ_BYTES, (int) filesize( $this->tree->path( 'at.json' ) ) );
		$this->assertIsArray( Manifest::read( $this->tree->path( 'at.json' ) ) );
		$this->assertNull( Manifest::read( $this->tree->path( 'over.json' ) ) );
	}

	// --------------------------------------------------- the storage-slug race

	public function testALostSlugRaceAdoptsTheWinnersDirectory(): void {
		// The row exists — another request wrote it — but this worker's read
		// misses it, which is what a stale `notoptions` entry does.
		$winner = str_repeat( 'b', 32 );

		$GLOBALS['fiction_drafts_test_options'][ StorageLocator::OPTION_SLUG ]       = $winner;
		$GLOBALS['fiction_drafts_test_option_misses'][ StorageLocator::OPTION_SLUG ] = 1;

		$this->assertSame( $winner, ( new StorageLocator() )->slug() );
	}

	/**
	 * The control: with no row at all, a fresh slug is generated and kept.
	 */
	public function testAnUncontendedSlugIsGeneratedAndStored(): void {
		$slug = ( new StorageLocator() )->slug();

		$this->assertSame( 32, strlen( $slug ) );
		$this->assertSame( $slug, $GLOBALS['fiction_drafts_test_options'][ StorageLocator::OPTION_SLUG ] );
	}

	// ------------------------------------------- one capability, every caller

	public function testTheMenuAndTheRestGateAgreeOnSingleSite(): void {
		$this->assertSame( 'manage_options', AbstractController::capability() );
	}

	public function testTheMenuAndTheRestGateAgreeOnMultisite(): void {
		$GLOBALS['fiction_drafts_test_multisite'] = true;

		// The bug this replaces: the menu asked for `manage_options` while the
		// REST routes required `manage_network_options`, so a subsite
		// administrator saw a screen whose every request was refused.
		$this->assertSame( 'manage_network_options', AbstractController::capability() );
	}

	public function testAdminPageAsksForTheCapabilityRatherThanTheConstant(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/AdminPage.php' );

		$this->assertStringContainsString( 'AbstractController::capability()', $source );
		$this->assertStringNotContainsString( 'current_user_can( AbstractController::CAPABILITY )', $source );
	}

	// --------------------------------------- one projection, three callers

	public function testTheStageAndTheWriterProjectAnEntryIdentically(): void {
		$name  = 'wp-content/uploads/2026/08/a-fairly-ordinary-wordpress-filename.jpg';
		$bytes = 4096;

		$this->assertSame(
			$bytes + EntryFootprint::OVERHEAD_BYTES + ( 2 * strlen( $name ) ),
			EntryFootprint::of( $bytes, $name )
		);
	}

	public function testNoOneKeepsAPrivateCopyOfTheProjection(): void {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$files = [ '/Archive/ZipWriter.php', '/Archive/PclZipWriter.php', '/Backup/Stages/ArchiveStage.php' ];

		foreach ( $files as $file ) {
			$source = self::code( $root . $file );

			$this->assertStringNotContainsString(
				'ENTRY_OVERHEAD_BYTES = 100',
				$source,
				$file . ' still declares its own copy of the per-entry overhead.'
			);
			$this->assertStringContainsString( 'EntryFootprint::of(', $source, $file . ' does not use the shared projection.' );
		}
	}

	public function testTheWriterIsStillUsable(): void {
		// The control for the refactor: the projection moved, so prove the
		// thing that uses it still accounts for a volume's size.
		$writer = new ZipWriter( 0 );
		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->addFromString( 'a.txt', str_repeat( 'x', 1000 ) );

		$this->assertSame( 1, $writer->entryCount() );
		$this->assertGreaterThanOrEqual( 1000, $writer->bytesWritten() );

		$writer->close();
	}

	/**
	 * Source with comments stripped — the doc blocks name the constants these
	 * assertions look for.
	 */
	private static function code( string $path ): string {
		$out = '';

		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			$out .= is_array( $token ) ? $token[1] : $token;
		}

		return $out;
	}
}
