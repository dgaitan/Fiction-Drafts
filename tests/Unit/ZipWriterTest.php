<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Archive\ArchiveWriterFactory;
use FictionDrafts\Archive\PclZipWriter;
use FictionDrafts\Archive\ZipWriter;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The writer, and the two things about it that are not obvious.
 *
 * The descriptor rule is proved live, under a lowered `ulimit -n`, in the
 * sprint's integration harness — a unit test on a machine with a million
 * descriptors cannot fail whether the rule is there or not.  What is proved
 * here is the rest of the contract, including the entry-count rewind that the
 * whole resume model rests on.
 */
final class ZipWriterTest extends TestCase {

	private TempTree $tree;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();

		$this->tree = new TempTree( 'fd-zip' );
	}

	protected function tearDown(): void {
		$this->tree->remove();

		parent::tearDown();
	}

	/**
	 * @return array<int, string>
	 */
	private function namesIn( string $path ): array {
		$zip = new ZipArchive();

		$this->assertTrue( $zip->open( $path ) === true, 'the volume should open' );

		$names = [];

		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$names[] = (string) $zip->getNameIndex( $index );
		}

		$zip->close();

		return $names;
	}

	/** ISC-277 */
	public function test_it_implements_the_archive_writer_contract(): void {
		$this->assertInstanceOf( \FictionDrafts\Contracts\ArchiveWriter::class, new ZipWriter() );
	}

	/** ISC-278, ISC-284 */
	public function test_an_entry_is_stored_under_its_relative_name_only(): void {
		$source = $this->tree->file( 'wp-content/themes/x/style.css', 'body{}' );

		$writer = new ZipWriter();
		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->addFile( $source, 'wp-content/themes/x/style.css' );
		$writer->close();

		$names = $this->namesIn( $this->tree->path( 'v.zip' ) );

		$this->assertSame( [ 'wp-content/themes/x/style.css' ], $names );
		$this->assertStringNotContainsString( $this->tree->root, $names[0] );
		$this->assertStringNotContainsString( '..', $names[0] );
	}

	public function test_generated_content_is_added_from_a_string(): void {
		$writer = new ZipWriter();
		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->addFromString( 'manifest.json', '{"a":1}' );
		$writer->close();

		$zip = new ZipArchive();
		$zip->open( $this->tree->path( 'v.zip' ) );
		$contents = $zip->getFromName( 'manifest.json' );
		$zip->close();

		$this->assertSame( '{"a":1}', $contents );
	}

	/** ISC-279 — the rule fires, counted rather than assumed */
	public function test_the_volume_is_closed_and_reopened_every_two_hundred_entries(): void {
		$writer = new ZipWriter( 200 );
		$writer->open( $this->tree->path( 'v.zip' ) );

		for ( $index = 0; $index < 205; ++$index ) {
			$writer->addFromString( sprintf( 'f%04d.txt', $index ), 'x' );

			// After the 200th entry the volume has been flushed to disk, so it
			// is readable *while still open*.  Before it, nothing is there.
			if ( 199 === $index ) {
				$this->assertFileExists( $this->tree->path( 'v.zip' ) );
				$this->assertCount( 200, $this->namesIn( $this->tree->path( 'v.zip' ) ) );
			}
		}

		$writer->close();

		$this->assertCount( 205, $this->namesIn( $this->tree->path( 'v.zip' ) ) );
	}

	/** ISC-279 — the control: with the rule off, nothing reaches disk mid-run */
	public function test_with_reopening_disabled_nothing_is_flushed_until_close(): void {
		$writer = new ZipWriter( 0 );
		$writer->open( $this->tree->path( 'v.zip' ) );

		for ( $index = 0; $index < 205; ++$index ) {
			$writer->addFromString( sprintf( 'f%04d.txt', $index ), 'x' );
		}

		$this->assertFileDoesNotExist( $this->tree->path( 'v.zip' ) );

		$writer->close();

		$this->assertCount( 205, $this->namesIn( $this->tree->path( 'v.zip' ) ) );
	}

	/** ISC-282 */
	public function test_entry_count_and_bytes_describe_the_open_volume(): void {
		$source = $this->tree->file( 'big.bin', str_repeat( 'x', 4096 ) );

		$writer = new ZipWriter();
		$writer->open( $this->tree->path( 'v.zip' ) );

		$this->assertSame( 0, $writer->entryCount() );
		$this->assertSame( 0, $writer->bytesWritten() );

		$writer->addFile( $source, 'big.bin' );

		$this->assertSame( 1, $writer->entryCount() );
		$this->assertGreaterThanOrEqual( 4096, $writer->bytesWritten() );

		$writer->close();
	}

	/** ISC-283 */
	public function test_close_is_safe_when_nothing_is_open(): void {
		$writer = new ZipWriter();

		$writer->close();
		$writer->close();

		$this->assertSame( 0, $writer->entryCount() );
	}

	/** ISC-285 — the rewind the whole resume model rests on */
	public function test_truncate_to_removes_every_entry_past_the_count(): void {
		$writer = new ZipWriter();
		$writer->open( $this->tree->path( 'v.zip' ) );

		foreach ( [ 'a.txt', 'b.txt', 'c.txt', 'd.txt' ] as $name ) {
			$writer->addFromString( $name, $name );
		}

		$writer->close();

		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->truncateTo( 2 );

		$this->assertSame( 2, $writer->entryCount() );

		$writer->close();

		$this->assertSame( [ 'a.txt', 'b.txt' ], $this->namesIn( $this->tree->path( 'v.zip' ) ) );
	}

	public function test_truncate_to_zero_starts_the_volume_over(): void {
		$writer = new ZipWriter();
		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->addFromString( 'a.txt', 'a' );
		$writer->close();

		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->truncateTo( 0 );
		$writer->addFromString( 'z.txt', 'z' );
		$writer->close();

		$this->assertSame( [ 'z.txt' ], $this->namesIn( $this->tree->path( 'v.zip' ) ) );
	}

	/**
	 * ISC-287 — why the cursor counts entries and not bytes.
	 *
	 * This is the measurement the design rests on, kept as a test so that a
	 * future libzip cannot quietly change the answer.
	 */
	public function test_byte_truncating_a_zip_destroys_it(): void {
		$writer = new ZipWriter();
		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->addFromString( 'a.txt', str_repeat( 'a', 100 ) );
		$writer->close();

		$oneEntry = (int) filesize( $this->tree->path( 'v.zip' ) );

		$writer->open( $this->tree->path( 'v.zip' ) );
		$writer->addFromString( 'b.txt', str_repeat( 'b', 100 ) );
		$writer->close();

		$handle = fopen( $this->tree->path( 'v.zip' ), 'r+b' );
		ftruncate( $handle, $oneEntry );
		fclose( $handle );

		$zip = new ZipArchive();

		$this->assertNotTrue(
			$zip->open( $this->tree->path( 'v.zip' ) ),
			'a zip truncated to a previously valid length must not open — this is why the cursor counts entries'
		);
	}

	/** ISC-291 */
	public function test_the_factory_prefers_ext_zip_when_it_exists(): void {
		$this->assertInstanceOf( ZipWriter::class, ( new ArchiveWriterFactory( true ) )->create() );
	}

	/** ISC-292 */
	public function test_the_factory_falls_back_to_pclzip_without_ext_zip(): void {
		if ( ! PclZipWriter::isAvailable() ) {
			$this->markTestSkipped( 'PclZip is only reachable inside a WordPress install.' );
		}

		$this->assertInstanceOf( PclZipWriter::class, ( new ArchiveWriterFactory( false ) )->create() );
	}

	public function test_the_writer_filter_can_replace_the_choice(): void {
		$replacement = new ZipWriter( 5 );

		fiction_drafts_test_add_filter(
			ArchiveWriterFactory::FILTER,
			static fn (): object => $replacement
		);

		$this->assertSame( $replacement, ( new ArchiveWriterFactory( true ) )->create() );
	}
}
