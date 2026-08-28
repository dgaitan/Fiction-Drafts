<?php

declare( strict_types=1 );

namespace FictionDrafts\Archive;

use FictionDrafts\Contracts\ArchiveWriter;
use RuntimeException;

/**
 * Chooses a writer, and is the seam that lets the choice be tested.
 *
 * `ext-zip` is present on the overwhelming majority of hosts and is faster and
 * far better behaved than PclZip, so it wins whenever it exists.  The point of
 * putting the decision behind a factory is that "what happens without ext-zip"
 * becomes a thing a test can ask, rather than a thing a host has to be found
 * for.
 */
final class ArchiveWriterFactory {

	public const FILTER = 'fiction_drafts/archive_writer';

	/**
	 * @param bool|null $zipAvailable Override the extension probe; null asks the runtime.
	 */
	public function __construct( private readonly ?bool $zipAvailable = null ) {}

	/**
	 * @throws RuntimeException When neither writer can be used.
	 */
	public function create(): ArchiveWriter {
		$writer = $this->select();

		/** @var mixed $filtered */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook name is self::FILTER, 'fiction_drafts/archive_writer'; the sniff cannot resolve a constant.
		$filtered = apply_filters( self::FILTER, $writer );

		return $filtered instanceof ArchiveWriter ? $filtered : $writer;
	}

	/**
	 * @throws RuntimeException When neither writer can be used.
	 */
	private function select(): ArchiveWriter {
		if ( $this->zipAvailable ?? ZipWriter::isAvailable() ) {
			return new ZipWriter();
		}

		if ( PclZipWriter::isAvailable() ) {
			return new PclZipWriter();
		}

		throw new RuntimeException(
			'Neither the zip extension nor PclZip is available, so no archive can be written.'
		);
	}
}
