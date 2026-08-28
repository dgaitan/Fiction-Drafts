<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup\Stages;

use FictionDrafts\Archive\ArchiveWriterFactory;
use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Backup\Manifest;
use FictionDrafts\Contracts\ArchiveWriter;
use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\Settings;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Storage\StorageLocator;
use RuntimeException;

/**
 * Puts the dump and every scanned file into one or more zip volumes.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 *
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
 *
 * The base64 here is not obfuscation, it is transport: a path on a Linux
 * filesystem is a byte string with no encoding, and JSON is UTF-8 only.  A
 * `wp-content/uploads` name inherited from a Latin-1 host makes json_encode()
 * return false, and a dropped line means a file that is missing from the
 * backup and missing from every check that compares the two.
 *
 * `files.jsonl` is read one line at a time from a byte offset; loading it to
 * find line 40,000 would defeat the reason it is a file at all.
 *
 * ## The resume boundary, in the unit an archive admits
 *
 * DatabaseStage rewinds `database.sql` to a byte length.  That does not work
 * here: a zip's central directory is written at the *end* of the file, so
 * truncating to a length that was valid five entries ago produces something no
 * reader will open — measured, not assumed.  The cursor therefore counts
 * **entries**, and every resume calls `truncateTo()` before adding anything.
 * The principle is Sprint 3's unchanged — discard whatever the persisted
 * cursor does not account for — only the unit differs.
 *
 * ## The rollover decision happens before the add
 *
 * If a volume were sealed *after* the entry that overflowed it, that entry
 * would sit in the old volume while the cursor said it belonged to the new
 * one, and the union of the volumes would differ from `files.jsonl` by exactly
 * one file.  That is the classic way this is got wrong, and it is silent.
 */
final class ArchiveStage implements Stage {

	public const ID = 'archive';

	public const FILTER_STEP_BYTES = 'fiction_drafts/archive_step_bytes';

	/**
	 * How many source bytes one step will add before stopping.
	 *
	 * The forward-progress rule guarantees at least one unit per step; without
	 * a byte bound, one unit can be a 4 GB video and every step overruns its
	 * budget by minutes.  64 MiB is small enough to keep a step inside a
	 * PHP-FPM request and large enough that a normal site is not spending its
	 * time on queue round-trips.
	 */
	public const DEFAULT_STEP_BYTES = 67108864;

	/**
	 * Fixed part of a zip entry's overhead: local header, central directory
	 * record, and the end-of-directory share.  The name is stored twice, so
	 * the variable part is added per entry — WordPress paths of 150 characters
	 * are ordinary, and a flat constant underestimates them by more than
	 * twofold.
	 */
	private const ENTRY_OVERHEAD_BYTES = 100;

	/**
	 * Entries before a volume is sealed regardless of its size.
	 *
	 * PclZip writes no ZIP64 record, so past 65,535 entries a volume's count
	 * wraps and many extractors read it as `count mod 65536` — a restore that
	 * silently loses files with no error anywhere.  ZipArchive handles more,
	 * but a ceiling that applies to both writers means the resume and volume
	 * logic has one behaviour rather than two.
	 */
	private const MAX_ENTRIES_PER_VOLUME = 60000;

	/**
	 * Generated files added ahead of the scan, in this order.
	 *
	 * `manifest.json` is written by PrepareStage, which runs immediately before
	 * this stage.  The copy that goes inside the archive carries an empty
	 * `volumes` array — it is added as entry two, before any volume is sealed,
	 * and a file cannot contain its own checksum.  The complete copy is written
	 * beside the volumes by FinalizeStage.
	 *
	 * @var array<int, string>
	 */
	private const EXTRAS = [ DatabaseStage::OUTPUT, Manifest::FILENAME ];

	/**
	 * @param string|null $root Absolute path entry names are relative to, or null for ABSPATH.
	 */
	public function __construct(
		private readonly StorageLocator $storage,
		private readonly ArchiveWriterFactory $writers,
		private readonly ?SettingsRepository $settings = null,
		private readonly ?string $root = null
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Building the archive', 'fiction-drafts' );
	}

	/**
	 * Every job produces an archive, even a database-only one.
	 */
	public function appliesTo( BackupJob $job ): bool {
		return true;
	}

	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		$workingDir = $this->workingDir( $job );
		$root       = untrailingslashit( $this->root ?? ABSPATH );

		$extras    = $this->extras( $workingDir );
		$scanned   = $this->scanPath( $workingDir );
		$total     = count( $extras ) + $this->countLines( $scanned );
		$maxVolume = $this->maxVolumeBytes( $job );
		$stepBytes = $this->stepBytes();

		$resuming = ! $cursor->isStart();

		$index   = $resuming ? $cursor->getInt( 'line' ) : 0;
		$offset  = $resuming ? $cursor->getInt( 'offset' ) : 0;
		$volume  = $resuming ? max( 1, $cursor->getInt( 'volume' ) ) : 1;
		$entries = $resuming ? $cursor->getInt( 'entries' ) : 0;
		$vline   = $resuming ? $cursor->getInt( 'vline' ) : 0;
		$voffset = $resuming ? $cursor->getInt( 'voffset' ) : 0;

		if ( ! $resuming ) {
			// A previous attempt at this job may have left volumes behind.  If
			// this run finishes before reaching them they would be checksummed
			// and offered for download as if they belonged to it.
			$this->removeStaleVolumes( $job, 0 );
		}

		$writer = $this->writers->create();
		$writer->open( $this->volumePath( $job, $volume ) );

		// The resume boundary.  Entries past the persisted count are entries
		// whose cursor never landed; adding them again is what duplicates a
		// file across a step boundary.  A fresh start passes zero, which wipes
		// any volume left behind by an earlier attempt at the same job.
		if ( $writer->entryCount() < $entries ) {
			// The row was committed but the volume's pages were not — a power
			// loss between the two.  The cursor's entry count no longer
			// describes the file, so rebuild the volume from the line it
			// started at rather than trusting a count that cannot be reached.
			$writer->truncateTo( 0 );

			$index   = $vline;
			$offset  = $voffset;
			$entries = 0;
		} else {
			$writer->truncateTo( $entries );
		}

		$handle    = null;
		$processed = 0;
		$added     = 0;

		try {
			do {
				if ( $index >= $total ) {
					$writer->close();
					$this->removeStaleVolumes( $job, $volume );

					return StageResult::complete( $processed, $total );
				}

				$lineStart   = $index;
				$offsetStart = $offset;

				if ( $index < count( $extras ) ) {
					$unit = [
						'source' => $workingDir . '/' . $extras[ $index ],
						'name'   => $extras[ $index ],
					];
				} else {
					if ( null === $handle ) {
						$handle = $this->openScan( $scanned );

						$this->assertLineStart( $handle, $offset, $scanned );

						fseek( $handle, $offset );
					}

					$line = fgets( $handle );

					if ( false === $line ) {
						// The list is shorter than its own line count said.
						// Treat it as the end rather than looping on a read
						// that will keep failing.
						$index = $total;

						continue;
					}

					$offset = (int) ftell( $handle );
					$unit   = $this->unitFromLine( $line, $root );
				}

				++$index;
				++$processed;

				if ( null === $unit || ! is_file( $unit['source'] ) ) {
					// A file removed between the scan and here — a cache
					// purge, a cron job.  One missing file is not a reason to
					// fail a backup, and the manifest's count will show it.
					continue;
				}

				$size = filesize( $unit['source'] );
				$size = false === $size ? 0 : $size;

				$projected = $size + self::ENTRY_OVERHEAD_BYTES + ( 2 * strlen( $unit['name'] ) );

				// Before the add, never after.  An entry decided into the old
				// volume and recorded against the new one is the boundary
				// off-by-one, and it is silent.
				//
				// entryCount() > 0 is what lets a single file larger than a
				// whole volume through: an empty volume never rolls, so the
				// file lands in one of its own instead of bouncing forever.
				if ( $writer->entryCount() > 0
					&& ( $writer->bytesWritten() + $projected > $maxVolume
						|| $writer->entryCount() >= self::MAX_ENTRIES_PER_VOLUME ) ) {
					$writer->close();

					++$volume;

					$writer->open( $this->volumePath( $job, $volume ) );
					$writer->truncateTo( 0 );

					$entries = 0;
					$vline   = $lineStart;
					$voffset = $offsetStart;
				}

				$writer->addFile( $unit['source'], $unit['name'] );

				$entries = $writer->entryCount();
				$added  += $size;
			} while ( ! $budget->exhausted() && $added < $stepBytes );

			$writer->close();
		} finally {
			$writer->close();

			if ( null !== $handle ) {
				fclose( $handle );
			}
		}

		return StageResult::incomplete(
			StageCursor::fromArray(
				[
					'line'    => $index,
					'offset'  => $offset,
					'volume'  => $volume,
					'entries' => $entries,
					'vline'   => $vline,
					'voffset' => $voffset,
				]
			),
			$processed,
			$total
		);
	}

	/**
	 * Refuse an offset that does not sit at the start of a line.
	 *
	 * The offset addresses one particular `files.jsonl`.  If the scan ever ran
	 * again — a restarted job, a lost scan cursor — the file behind the offset
	 * is different, and resuming from it would read half of one line and half
	 * of another.  The byte before a line start is a newline; nothing else is.
	 *
	 * @param resource $handle Open read handle on the scan list.
	 * @throws RuntimeException When the offset is not a line boundary.
	 */
	private function assertLineStart( $handle, int $offset, string $path ): void {
		if ( 0 === $offset ) {
			return;
		}

		$size = filesize( $path );

		if ( false === $size || $offset > $size ) {
			throw new RuntimeException( 'The scanned file list is shorter than the archive cursor.' );
		}

		fseek( $handle, $offset - 1 );

		if ( "\n" !== fread( $handle, 1 ) ) {
			throw new RuntimeException( 'The archive cursor does not point at the start of a line.' );
		}
	}

	/**
	 * One `files.jsonl` line as a source path and a stored name.
	 *
	 * `a` carries an absolute source path for the one entry that is not at
	 * `$root/$path` — a `wp-config.php` kept above the site root.  Reading it
	 * generically here means this stage never learns why.
	 *
	 * @return array{source: string, name: string}|null
	 */
	private function unitFromLine( string $line, string $root ): ?array {
		$decoded = json_decode( trim( $line ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['p'] ) || ! is_string( $decoded['p'] ) ) {
			return null;
		}

		$path = (string) $decoded['p'];

		// `b` marks a path the scan could not represent in JSON — a filename
		// that is not valid UTF-8, which a site migrated from an older host
		// has plenty of.  A zip entry name is a byte string, so the original
		// name goes into the archive unchanged.
		if ( isset( $decoded['b'] ) ) {
			$decodedPath = base64_decode( $path, true );

			if ( false === $decodedPath ) {
				return null;
			}

			$path = $decodedPath;
		}

		$name = ltrim( str_replace( '\\', '/', $path ), '/' );

		// An entry name that climbs out of the archive root is either a bug in
		// the scan or a crafted list; either way it does not belong in a file
		// someone will later extract.
		if ( '' === $name || str_contains( $name, '../' ) ) {
			return null;
		}

		$source = $root . '/' . $name;

		if ( isset( $decoded['a'] ) && is_string( $decoded['a'] ) ) {
			$absolute = isset( $decoded['b'] ) ? base64_decode( $decoded['a'], true ) : $decoded['a'];
			$source   = false === $absolute ? $source : $absolute;
		}

		return [
			'source' => $source,
			'name'   => $name,
		];
	}

	/**
	 * Generated files present in the working directory, in a fixed order.
	 *
	 * @return array<int, string>
	 */
	private function extras( string $workingDir ): array {
		return array_values(
			array_filter(
				self::EXTRAS,
				static fn ( string $name ): bool => is_file( $workingDir . '/' . $name )
			)
		);
	}

	/**
	 * `…-part01.zip`, zero-padded so a directory listing is in volume order.
	 *
	 * Delegated rather than derived here: FinalizeStage, the retention sweep,
	 * and cancellation all have to find these files again long after this stage
	 * has gone, and two copies of a naming rule drift.
	 */
	private function volumePath( BackupJob $job, int $volume ): string {
		return $this->naming()->pathFor( $job, $volume );
	}

	private function naming(): VolumeNaming {
		return new VolumeNaming( $this->storage->baseDir() );
	}

	/**
	 * Remove volumes above the one the job actually ended on.
	 *
	 * A job that rolled over to part03, crashed, and resumed with a smaller
	 * budget can finish at part02 and leave part03 behind.  Sprint 5 lists
	 * volumes off the disk, so a stale one would be checksummed, recorded, and
	 * offered for download.
	 */
	private function removeStaleVolumes( BackupJob $job, int $last ): void {
		for ( $volume = $last + 1; $volume <= $last + 200; ++$volume ) {
			$path = $this->volumePath( $job, $volume );

			if ( ! is_file( $path ) ) {
				return;
			}

			wp_delete_file( $path );
		}
	}

	private function scanPath( string $workingDir ): string {
		return $workingDir . '/' . FileScanStage::OUTPUT;
	}

	/**
	 * @return resource
	 * @throws RuntimeException When the scan list cannot be read.
	 */
	private function openScan( string $path ) {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new RuntimeException( 'Could not read the scanned file list.' );
		}

		return $handle;
	}

	private function countLines( string $path ): int {
		if ( ! is_file( $path ) ) {
			return 0;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return 0;
		}

		$lines = 0;

		while ( false !== fgets( $handle ) ) {
			++$lines;
		}

		fclose( $handle );

		return $lines;
	}

	/**
	 * How large a volume may get: the job's own figure, else the setting.
	 *
	 * The 64 KiB floor is not a preference, it is arithmetic — below it the
	 * per-entry zip headers start to outweigh the files.
	 */
	private function maxVolumeBytes( BackupJob $job ): int {
		$requested = $job->option( BackupJob::OPTION_MAX_VOLUME_BYTES );

		if ( is_numeric( $requested ) ) {
			return max( 65536, (int) $requested );
		}

		$settings = $this->settings?->get();

		return $settings instanceof Settings
			? $settings->maxVolumeBytes()
			: Settings::DEFAULT_MAX_VOLUME_BYTES;
	}

	private function stepBytes(): int {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook name is self::FILTER_STEP_BYTES, 'fiction_drafts/archive_step_bytes'; the sniff cannot resolve a constant.
		$bytes = (int) apply_filters( self::FILTER_STEP_BYTES, self::DEFAULT_STEP_BYTES );

		return max( 1, $bytes );
	}

	/**
	 * @throws RuntimeException When the job working directory cannot be created.
	 */
	private function workingDir( BackupJob $job ): string {
		$this->storage->ensure();

		$dir = $this->storage->workingDir( $job->uuid );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Could not create the job working directory.' );
		}

		return $dir;
	}
}
