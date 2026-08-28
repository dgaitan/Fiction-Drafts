<?php

declare( strict_types=1 );

namespace FictionDrafts\Archive;

use FictionDrafts\Contracts\ArchiveWriter;
use RuntimeException;
use ZipArchive;

/**
 * An `ext-zip` archive writer that survives a hundred thousand entries.
 *
 * ## The descriptor rule
 *
 * ZipArchive holds an open file descriptor for every file added, and releases
 * them only at close().  Past roughly a thousand entries a site hits its
 * `ulimit -n` and addFile() starts returning false with no useful error — the
 * single most common way a hand-rolled backup plugin fails on a real site, and
 * one that never reproduces on a developer machine where the limit is a
 * million.  So the volume is closed and reopened every REOPEN_EVERY entries.
 *
 * $reopenEvery is a constructor argument rather than a constant so a test can
 * turn the rule off.  A test that only proves 5,000 files archive successfully
 * proves nothing on a host with a high limit; the control — the same run with
 * reopening disabled, which must fail — is what gives the first result meaning.
 *
 * ## Size is projected, not measured
 *
 * ZipArchive writes nothing until close(), so filesize() on an open volume is
 * stale by however much has been added since. bytesWritten() therefore reports
 * the size on disk at the last close plus the source bytes added since, plus a
 * header allowance per entry. Compression only ever shrinks, so the figure is
 * an upper bound: a volume rolls over slightly early rather than overshooting.
 */
final class ZipWriter implements ArchiveWriter {

	public const REOPEN_EVERY = 200;

	private ?ZipArchive $zip = null;

	private string $path = '';

	private int $entries = 0;

	private int $sinceReopen = 0;

	private int $baselineBytes = 0;

	private int $pendingBytes = 0;

	public function __construct( private readonly int $reopenEvery = self::REOPEN_EVERY ) {}

	public static function isAvailable(): bool {
		return class_exists( ZipArchive::class );
	}

	/**
	 * @throws RuntimeException When the archive cannot be opened.
	 */
	public function open( string $path ): void {
		if ( null !== $this->zip && $this->path === $path ) {
			return;
		}

		$this->close();

		$this->path = $path;
		$this->zip  = $this->openHandle( $path );

		$this->entries       = $this->zip->numFiles;
		$this->sinceReopen   = 0;
		$this->baselineBytes = $this->sizeOnDisk();
		$this->pendingBytes  = 0;
	}

	/**
	 * @throws RuntimeException When the file cannot be added.
	 */
	public function addFile( string $absolutePath, string $entryName ): void {
		$zip = $this->handle();

		if ( ! $zip->addFile( $absolutePath, $entryName ) ) {
			throw new RuntimeException(
				sprintf( 'Could not add "%s" to the archive.', $entryName )
			);
		}

		$size = filesize( $absolutePath );

		$this->recordEntry( $entryName, false === $size ? 0 : $size );
	}

	/**
	 * @throws RuntimeException When the content cannot be added.
	 */
	public function addFromString( string $entryName, string $contents ): void {
		$zip = $this->handle();

		if ( ! $zip->addFromString( $entryName, $contents ) ) {
			throw new RuntimeException(
				sprintf( 'Could not add "%s" to the archive.', $entryName )
			);
		}

		$this->recordEntry( $entryName, strlen( $contents ) );
	}

	public function entryCount(): int {
		return $this->entries;
	}

	public function bytesWritten(): int {
		return $this->baselineBytes + $this->pendingBytes;
	}

	/**
	 * Discard every entry at index >= $entries.
	 *
	 * A count of zero starts the volume over: the file is removed rather than
	 * emptied, so a volume left half-written by a step whose cursor never
	 * persisted cannot leave a stale central directory behind.
	 *
	 * @throws RuntimeException When the archive cannot be reopened afterwards.
	 */
	public function truncateTo( int $entries ): void {
		if ( '' === $this->path ) {
			return;
		}

		$path = $this->path;

		$this->close();

		if ( $entries <= 0 ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}

			$this->open( $path );

			return;
		}

		$zip     = $this->openHandle( $path );
		$present = $zip->numFiles;

		if ( $present < $entries ) {
			// The cursor accounts for entries the volume does not have: the
			// row was committed but the archive's pages were not.  Silently
			// carrying on would advance past those files and ship a short
			// archive, so say so and let the caller rebuild the volume.
			$zip->close();

			throw new RuntimeException(
				sprintf(
					'Archive volume "%s" holds %d entries but the cursor accounts for %d.',
					basename( $path ),
					$present,
					$entries
				)
			);
		}

		for ( $index = $present - 1; $index >= $entries; --$index ) {
			$zip->deleteIndex( $index );
		}

		$zip->close();

		$this->path = '';

		$this->open( $path );
	}

	/**
	 * Flush and close the volume.
	 *
	 * `ZipArchive::close()` is where every buffered entry is actually written,
	 * and it returns false when that write fails — a full disk being the case
	 * that matters. Discarding that answer produces the worst outcome this
	 * plugin has: a volume that is hashed, recorded, and offered for download
	 * while being short of what it claims. It throws instead.
	 *
	 * @throws RuntimeException When the archive could not be flushed.
	 */
	public function close(): void {
		if ( null !== $this->zip ) {
			$closed = $this->zip->close();
			$path   = $this->path;

			$this->zip = null;

			if ( false === $closed ) {
				throw new RuntimeException(
					sprintf( 'The archive volume "%s" could not be written. The disk may be full.', basename( (string) $path ) )
				);
			}
		}

		$this->baselineBytes = $this->sizeOnDisk();
		$this->pendingBytes  = 0;
		$this->sinceReopen   = 0;
	}

	/**
	 * Count the entry, then honour the descriptor rule.
	 */
	private function recordEntry( string $entryName, int $sourceBytes ): void {
		++$this->entries;
		++$this->sinceReopen;

		$this->pendingBytes += EntryFootprint::of( $sourceBytes, $entryName );

		if ( $this->reopenEvery > 0 && $this->sinceReopen >= $this->reopenEvery ) {
			$this->reopen();
		}
	}

	/**
	 * Flush the volume to disk and take a fresh handle, releasing descriptors.
	 *
	 * @throws RuntimeException When the archive cannot be reopened.
	 */
	private function reopen(): void {
		$path    = $this->path;
		$entries = $this->entries;

		$this->close();

		$this->zip           = $this->openHandle( $path );
		$this->entries       = $entries;
		$this->sinceReopen   = 0;
		$this->baselineBytes = $this->sizeOnDisk();
		$this->pendingBytes  = 0;
	}

	/**
	 * @throws RuntimeException When no volume is open.
	 */
	private function handle(): ZipArchive {
		if ( null === $this->zip ) {
			throw new RuntimeException( 'No archive volume is open.' );
		}

		return $this->zip;
	}

	/**
	 * @throws RuntimeException When ZipArchive refuses the path.
	 */
	private function openHandle( string $path ): ZipArchive {
		$zip    = new ZipArchive();
		$opened = $zip->open( $path, ZipArchive::CREATE );

		if ( true !== $opened ) {
			throw new RuntimeException(
				sprintf( 'Could not open the archive "%s" (code %s).', basename( $path ), (string) $opened )
			);
		}

		return $zip;
	}

	private function sizeOnDisk(): int {
		if ( '' === $this->path || ! is_file( $this->path ) ) {
			return 0;
		}

		$size = filesize( $this->path );

		return false === $size ? 0 : $size;
	}
}
