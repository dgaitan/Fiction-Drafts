<?php

declare( strict_types=1 );

namespace FictionDrafts\Archive;

/**
 * How much of a volume one entry is expected to occupy.
 *
 * Three things have to agree on this number, and they used to agree only by
 * coincidence: `ZipWriter` and `PclZipWriter` each measure a volume as it
 * fills, and `ArchiveStage` decides — before every add — whether the next
 * entry still fits. The constant and the arithmetic were written out once in
 * each of the three files.
 *
 * Nothing failed loudly when they matched, and nothing would have failed
 * loudly if they stopped: a writer that estimated high and a stage that
 * estimated low produce volumes that overshoot the configured maximum, and the
 * reverse produces volumes that roll over early. Both look like working
 * backups. One definition removes the class of bug rather than the instance.
 */
final class EntryFootprint {

	/**
	 * Fixed per-entry zip overhead: local header, central directory record,
	 * and a share of the end-of-central-directory record.
	 */
	public const OVERHEAD_BYTES = 100;

	/**
	 * The bytes one entry is projected to add.
	 *
	 * The name counts twice because a zip stores it twice — once in the local
	 * header, once in the central directory. WordPress paths of 150 characters
	 * are ordinary, so a flat constant underestimates a real entry by more
	 * than twofold.
	 *
	 * Deliberately an upper bound: compression only ever shrinks, so a volume
	 * rolls over slightly early rather than overshooting its limit.
	 */
	public static function of( int $sourceBytes, string $entryName ): int {
		return $sourceBytes + self::OVERHEAD_BYTES + ( 2 * strlen( $entryName ) );
	}
}
