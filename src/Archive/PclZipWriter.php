<?php

declare( strict_types=1 );

namespace FictionDrafts\Archive;

use FictionDrafts\Contracts\ArchiveWriter;
use PclZip;
use RuntimeException;

/**
 * The fallback writer, for a host without `ext-zip`.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
 *
 * WordPress ships PclZip and loads it for its own updater, so this costs the
 * plugin nothing but the shim.  It is a fallback rather than the default for
 * one reason: PclZip rewrites the entire archive on every add() call, so
 * adding files one at a time is quadratic.  Entries are therefore buffered and
 * flushed in a single call per batch — which is also why close() does the real
 * work here and addFile() does almost none.
 *
 * Descriptors are not the hazard they are for ZipArchive, because PclZip opens
 * and closes each source file as it copies it.  The buffer bounds memory
 * instead: it holds paths, never contents.
 */
final class PclZipWriter implements ArchiveWriter {

	public const FLUSH_EVERY = 200;


	private string $path = '';

	private ?PclZip $zip = null;

	/**
	 * Buffered entries: absolute source path and stored name.
	 *
	 * @var array<int, array{source: string, name: string, temp: bool}>
	 */
	private array $pending = [];

	private int $entries = 0;

	private int $baselineBytes = 0;

	private int $pendingBytes = 0;

	private string $tempDir = '';

	public function __construct( private readonly int $flushEvery = self::FLUSH_EVERY ) {}

	/**
	 * Is WordPress's bundled PclZip reachable?
	 *
	 * Loaded from `wp-admin/includes/`, never vendored a second time — two
	 * copies of a class this old in one request is how fatals happen.
	 */
	public static function isAvailable(): bool {
		if ( class_exists( PclZip::class ) ) {
			return true;
		}

		$bundled = ABSPATH . 'wp-admin/includes/class-pclzip.php';

		if ( ! is_file( $bundled ) ) {
			return false;
		}

		require_once $bundled;

		return class_exists( PclZip::class );
	}

	/**
	 * @throws RuntimeException When PclZip is not available.
	 */
	public function open( string $path ): void {
		if ( $this->path === $path && null !== $this->zip ) {
			return;
		}

		$this->close();

		if ( ! self::isAvailable() ) {
			throw new RuntimeException( 'PclZip is not available.' );
		}

		$this->path          = $path;
		$this->zip           = new PclZip( $path );
		$this->entries       = $this->countEntries();
		$this->baselineBytes = $this->sizeOnDisk();
		$this->pendingBytes  = 0;
		$this->pending       = [];
	}

	public function addFile( string $absolutePath, string $entryName ): void {
		$size = filesize( $absolutePath );

		$this->buffer( $absolutePath, $entryName, false, false === $size ? 0 : $size );
	}

	/**
	 * Generated content reaches PclZip through a temp file.
	 *
	 * PclZip has no add-from-memory entry point.  The temp file is removed as
	 * soon as the batch is flushed.
	 *
	 * @throws RuntimeException When the temporary file cannot be written.
	 */
	public function addFromString( string $entryName, string $contents ): void {
		// PCLZIP_OPT_REMOVE_ALL_PATH strips the directories from a source path
		// and keeps the filename, so the staged file's *basename* is the name
		// the entry ends up with.  It therefore has to be the entry's basename
		// exactly, with the uniqueness pushed into a directory above it.
		$directory = $this->tempDir() . '/' . md5( $entryName );

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		$temp = $directory . '/' . basename( $entryName );

		if ( false === file_put_contents( $temp, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- staging a temp file for PclZip, inside the plugin's own storage.
			throw new RuntimeException( sprintf( 'Could not stage "%s" for the archive.', $entryName ) );
		}

		$this->buffer( $temp, $entryName, true, strlen( $contents ) );
	}

	public function entryCount(): int {
		return $this->entries;
	}

	public function bytesWritten(): int {
		return $this->baselineBytes + $this->pendingBytes;
	}

	/**
	 * @throws RuntimeException When the archive cannot be rewritten.
	 */
	public function truncateTo( int $entries ): void {
		if ( '' === $this->path ) {
			return;
		}

		$path = $this->path;

		$this->flush();

		if ( $entries <= 0 ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}

			$this->zip           = null;
			$this->path          = '';
			$this->entries       = 0;
			$this->baselineBytes = 0;

			$this->open( $path );

			return;
		}

		$present = $this->countEntries();

		if ( $present < $entries ) {
			throw new RuntimeException(
				sprintf(
					'Archive volume "%s" holds %d entries but the cursor accounts for %d.',
					basename( $path ),
					$present,
					$entries
				)
			);
		}

		if ( $present > $entries ) {
			// deleteByIndex() is PclZip's own single-argument wrapper around
			// delete( PCLZIP_OPT_BY_INDEX, … ).  Using it keeps this call
			// inside a declared signature.
			$this->handle()->deleteByIndex( $entries . '-' . ( $present - 1 ) );
		}

		$this->entries       = $this->countEntries();
		$this->baselineBytes = $this->sizeOnDisk();
		$this->pendingBytes  = 0;
	}

	public function close(): void {
		$this->flush();

		$this->zip           = null;
		$this->baselineBytes = $this->sizeOnDisk();
		$this->pendingBytes  = 0;
	}

	private function buffer( string $source, string $entryName, bool $temp, int $bytes ): void {
		$this->pending[] = [
			'source' => $source,
			'name'   => $entryName,
			'temp'   => $temp,
		];

		++$this->entries;

		$this->pendingBytes += EntryFootprint::of( $bytes, $entryName );

		if ( $this->flushEvery > 0 && count( $this->pending ) >= $this->flushEvery ) {
			$this->flush();
		}
	}

	/**
	 * Write every buffered entry in as few PclZip calls as the names allow.
	 *
	 * Entries whose source path already ends in their stored name share a
	 * prefix that PCLZIP_OPT_REMOVE_PATH can strip, so they go in one call.
	 * The rest — generated content, and a `wp-config.php` that lives above the
	 * site root — are added individually with the path stripped and the stored
	 * directory put back.
	 *
	 * @throws RuntimeException When PclZip reports a failure.
	 */
	private function flush(): void {
		if ( [] === $this->pending ) {
			return;
		}

		$zip     = $this->handle();
		$grouped = [];
		$single  = [];

		foreach ( $this->pending as $entry ) {
			$suffix = '/' . $entry['name'];

			if ( ! $entry['temp'] && str_ends_with( $entry['source'], $suffix ) ) {
				$prefix = substr( $entry['source'], 0, -strlen( $suffix ) );

				$grouped[ $prefix ][] = $entry['source'];

				continue;
			}

			$single[] = $entry;
		}

		foreach ( $grouped as $prefix => $sources ) {
			$this->assertAdded( $this->add( $zip, $sources, [ PCLZIP_OPT_REMOVE_PATH, (string) $prefix ] ), $zip );
		}

		foreach ( $single as $entry ) {
			$directory = dirname( $entry['name'] );
			$options   = [ PCLZIP_OPT_REMOVE_ALL_PATH ];

			if ( '.' !== $directory && '' !== $directory ) {
				$options[] = PCLZIP_OPT_ADD_PATH;
				$options[] = $directory;
			}

			$this->assertAdded( $this->add( $zip, $entry['source'], $options ), $zip );

			if ( $entry['temp'] && is_file( $entry['source'] ) ) {
				wp_delete_file( $entry['source'] );
			}
		}

		$this->pending       = [];
		$this->entries       = $this->countEntries();
		$this->baselineBytes = $this->sizeOnDisk();
		$this->pendingBytes  = 0;
	}

	/**
	 * Call PclZip::add() with its option arguments.
	 *
	 * PclZip declares one parameter and reads the rest with func_get_args(),
	 * which is a shape no static analyser can follow.  Going through
	 * call_user_func_array() says out loud that the argument list is variadic
	 * by design, rather than exempting the call from analysis.
	 *
	 * @param  string|array<int, string> $files   One path, or a list of them.
	 * @param  array<int, mixed>         $options PCLZIP_OPT_* pairs.
	 * @return mixed PclZip's return value.
	 */
	private function add( PclZip $zip, string|array $files, array $options ): mixed {
		return call_user_func_array( [ $zip, 'add' ], array_merge( [ $files ], $options ) );
	}

	/**
	 * @param  mixed $result PclZip's return value: a list on success, 0 on failure.
	 * @throws RuntimeException When PclZip reports a failure.
	 */
	private function assertAdded( mixed $result, PclZip $zip ): void {
		if ( is_array( $result ) ) {
			return;
		}

		throw new RuntimeException(
			sprintf( 'PclZip refused the batch: %s', (string) $zip->errorInfo( true ) )
		);
	}

	/**
	 * @throws RuntimeException When no volume is open.
	 */
	private function handle(): PclZip {
		if ( null === $this->zip ) {
			throw new RuntimeException( 'No archive volume is open.' );
		}

		return $this->zip;
	}

	private function countEntries(): int {
		if ( null === $this->zip || ! is_file( $this->path ) ) {
			return 0;
		}

		$listing = $this->zip->listContent();

		return is_array( $listing ) ? count( $listing ) : 0;
	}

	private function sizeOnDisk(): int {
		if ( '' === $this->path || ! is_file( $this->path ) ) {
			return 0;
		}

		$size = filesize( $this->path );

		return false === $size ? 0 : $size;
	}

	private function tempDir(): string {
		if ( '' !== $this->tempDir ) {
			return $this->tempDir;
		}

		$this->tempDir = dirname( $this->path ) . '/.pclzip-staging';

		if ( ! is_dir( $this->tempDir ) ) {
			wp_mkdir_p( $this->tempDir );
		}

		return $this->tempDir;
	}
}
