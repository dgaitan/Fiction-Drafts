<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup\Stages;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Backup\Manifest;
use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Storage\StorageLocator;
use RuntimeException;
use ZipArchive;

/**
 * Seals the backup: hash every volume, record the ledger, clean up.
 *
 * ## Finding the volumes again
 *
 * ArchiveStage knows its final volume number and then loses it — StageRunner
 * resets the cursor at every stage boundary, because each stage counts in its
 * own units.  That is deliberate, not an oversight, so the number has to be
 * re-derived rather than remembered.
 *
 * It is derived by asking VolumeNaming for sequence 1, then 2, and stopping at
 * the first gap.  That is not the same thing as globbing the storage
 * directory, which Sprint 4 warns against: a glob picks up other jobs' volumes
 * and anything else shaped like one, while the sequence walk uses the exact
 * name this job's own formula produced, uuid fragment included.  ArchiveStage
 * removes any volume above the one it finished on, so a gap means the run
 * never reached it.
 *
 * ## Hashing streams
 *
 * `hash_file()` reads in blocks and never holds the file.  A 1.5 GiB volume
 * hashes in constant memory, which is the only reason a checksum per volume is
 * affordable inside a PHP-FPM request at all.
 *
 * ## Order of operations
 *
 * The sidecar manifest is written from the working directory's copy *before*
 * that directory is removed.  Reversed, the manifest would be read from a path
 * that had just been deleted and the sidecar would be a skeleton — which looks
 * exactly like a manifest, and is discovered a year later.
 */
final class FinalizeStage implements Stage {

	public const ID = 'finalize';

	/**
	 * Working files removed once the archive holds them.
	 *
	 * @var array<int, string>
	 */
	private const WORKING_FILES = [ DatabaseStage::OUTPUT, FileScanStage::OUTPUT, Manifest::FILENAME ];

	public function __construct(
		private readonly StorageLocator $storage,
		private readonly VolumeStore $volumes,
		private readonly JobStore $jobs
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Finishing up', 'fiction-drafts' );
	}

	public function appliesTo( BackupJob $job ): bool {
		return true;
	}

	/**
	 * One step.  Hashing is the only expensive part and it streams.
	 *
	 * @throws RuntimeException When the archive produced no volume at all.
	 */
	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		$naming     = VolumeNaming::forStorage( $this->storage );
		$sequences  = $naming->sequencesFor( $job );
		$workingDir = $this->storage->workingDir( $job->uuid );

		if ( [] === $sequences ) {
			// Reaching here with nothing on disk means the archive stage
			// reported success without producing a file.  Completing the job
			// would present an empty backup as a good one.
			throw new RuntimeException(
				__( 'The backup finished but produced no archive file. Nothing was saved.', 'fiction-drafts' )
			);
		}

		$sealed = [];
		$total  = 0;

		foreach ( $sequences as $sequence ) {
			$path  = $naming->pathFor( $job, $sequence );
			$bytes = filesize( $path );
			$bytes = false === $bytes ? 0 : $bytes;
			$hash  = hash_file( 'sha256', $path );

			if ( false === $hash ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: archive filename. */
						__( 'The archive volume %s could not be read to verify it.', 'fiction-drafts' ),
						$naming->filenameFor( $job, $sequence )
					)
				);
			}

			$sealed[] = new ArchiveVolume(
				jobUuid: $job->uuid,
				sequence: $sequence,
				filename: $naming->filenameFor( $job, $sequence ),
				path: $path,
				bytes: $bytes,
				sha256: $hash
			);

			$total += $bytes;
		}

		$this->assertVolumesAccountForTheScan( $workingDir, $sealed );

		$this->volumes->replaceFor( $job, $sealed );

		$this->writeSidecar( $naming, $job, $workingDir, $sealed );

		// After the sidecar, never before, and only once it is on disk and
		// reads back: the sidecar is built from the copy inside this directory,
		// and the write immediately follows gigabytes of archive, which is
		// exactly when a disk runs out. Removing the source of a manifest that
		// was never written leaves a backup nothing can describe.
		$this->storage->removeDirectory( $workingDir );

		// The runner writes status and timestamps; the size is this stage's to
		// report, because it is the first moment every volume has been measured.
		$this->jobs->save( $job->with( [ 'sizeBytes' => $total ] ) );

		do_action( 'fiction_drafts/job_sealed', $job, $sealed );

		return StageResult::complete( count( $sealed ), count( $sealed ) );
	}

	/**
	 * The manifest beside the volumes, carrying the ledger the inner copy
	 * could not.
	 *
	 * @param  array<int, ArchiveVolume> $sealed Hashed volumes in sequence order.
	 * @throws RuntimeException When the sidecar cannot be written or read back.
	 */
	private function writeSidecar( VolumeNaming $naming, BackupJob $job, string $workingDir, array $sealed ): void {
		$manifest = Manifest::read( $workingDir . '/' . Manifest::FILENAME );

		if ( null === $manifest ) {
			// PrepareStage always writes one, so an absent manifest means the
			// working directory was cleared under us.  The volumes are still
			// good and still hashed; a backup without a sidecar is worth more
			// than a backup this stage refused to finish.
			return;
		}

		$manifest['volumes'] = array_map(
			static fn ( ArchiveVolume $volume ): array => $volume->toArray(),
			$sealed
		);

		$path = $naming->manifestPathFor( $job );

		Manifest::write( $path, $manifest );

		// Read it back rather than trusting the write. file_put_contents()
		// reports a short write as a byte count, not as false, and a manifest
		// that decodes to null is indistinguishable from a missing one to every
		// later reader.
		if ( null === Manifest::read( $path ) ) {
			throw new RuntimeException(
				__( 'The backup manifest could not be saved. The archive is on disk but nothing describes it; check the free space and try again.', 'fiction-drafts' )
			);
		}
	}

	/**
	 * Refuse a volume set that holds fewer entries than the scan listed.
	 *
	 * The volume list is rebuilt by walking sequence numbers to the first gap.
	 * That is deliberate — a glob would pick up other jobs' files — but it
	 * means a volume missing from the middle silently truncates the backup to
	 * everything before it, which is precisely the failure this project keeps
	 * hunting.
	 *
	 * So the count is checked against something the archive did not produce:
	 * the file count the *scan* recorded. Entry totals are read from each
	 * volume's central directory, which costs no decompression. The comparison
	 * is one-directional because the generated extras — the dump, the
	 * manifest — add entries the scan never listed.
	 *
	 * @param  array<int, ArchiveVolume> $sealed Hashed volumes in sequence order.
	 * @throws RuntimeException When the volumes hold fewer entries than the scan listed.
	 */
	private function assertVolumesAccountForTheScan( string $workingDir, array $sealed ): void {
		$manifest = Manifest::read( $workingDir . '/' . Manifest::FILENAME );

		if ( null === $manifest || ! isset( $manifest['file_count'] ) || ! is_numeric( $manifest['file_count'] ) ) {
			return;
		}

		$expected = (int) $manifest['file_count'];
		$found    = 0;

		foreach ( $sealed as $volume ) {
			$zip = new ZipArchive();

			if ( true !== $zip->open( $volume->path ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: archive filename. */
						__( 'The archive volume %s could not be opened to verify it.', 'fiction-drafts' ),
						$volume->filename
					)
				);
			}

			$found += $zip->numFiles;

			$zip->close();
		}

		if ( $found < $expected ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: entries found, 2: files the scan listed. */
					__( 'The archive holds %1$d entries but the scan listed %2$d files. A volume is missing, so this backup is incomplete.', 'fiction-drafts' ),
					$found,
					$expected
				)
			);
		}
	}

	/**
	 * Named so a reader can see what the working directory held; the removal
	 * takes the directory as a whole rather than these files one by one.
	 *
	 * @return array<int, string>
	 */
	public static function workingFiles(): array {
		return self::WORKING_FILES;
	}
}
