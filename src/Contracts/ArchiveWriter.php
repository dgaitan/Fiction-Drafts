<?php

declare( strict_types=1 );

namespace FictionDrafts\Contracts;

/**
 * Writes entries into an archive volume.
 *
 * Implementations are responsible for the two things that break hand-rolled
 * backup plugins on real sites:
 *
 *   1. File descriptors.  ZipArchive holds an open descriptor for every added
 *      file until close() is called, so an implementation must periodically
 *      close and reopen rather than adding thousands of entries in one span.
 *   2. Volume size.  bytesWritten() lets the caller seal a volume and start
 *      the next one before any single file gets unwieldy.
 */
interface ArchiveWriter {

	/**
	 * Begin (or resume) writing the volume at $path.
	 */
	public function open( string $path ): void;

	/**
	 * Add a file from disk under the given archive-relative entry name.
	 */
	public function addFile( string $absolutePath, string $entryName ): void;

	/**
	 * Add generated content — a manifest, a SQL dump header — with no source file.
	 */
	public function addFromString( string $entryName, string $contents ): void;

	/**
	 * Entries added to the currently open volume.
	 */
	public function entryCount(): int;

	/**
	 * Bytes written to the currently open volume so far.
	 */
	public function bytesWritten(): int;

	/**
	 * Discard every entry at index >= $entries, leaving a valid archive.
	 *
	 * This is the resume boundary, in the only unit an archive admits.
	 * DatabaseStage rewinds `database.sql` by byte length; an archive cannot be
	 * rewound that way, because its index is written at the *end* of the file —
	 * truncating to a length that was valid five entries ago leaves something
	 * no reader will open.  So the position is an entry count, and this is how
	 * a repeated step stops duplicating what it already added.
	 *
	 * truncateTo( 0 ) starts the volume over from nothing.
	 */
	public function truncateTo( int $entries ): void;

	/**
	 * Flush and close the current volume.  Safe to call when nothing is open.
	 */
	public function close(): void;
}
