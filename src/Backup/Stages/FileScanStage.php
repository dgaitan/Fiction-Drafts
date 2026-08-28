<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup\Stages;

use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Files\FileWalker;
use FictionDrafts\Files\ScanScope;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Storage\StorageLocator;
use RuntimeException;

/**
 * Walks the site once and writes `files.jsonl`.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
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
 * WP_Filesystem's write() takes a whole file's contents as a string, so using
 * it to append a line to a 100k-line list would mean holding the list in
 * memory to add to it — the exact thing this stage exists to avoid.  Every
 * path written is inside the plugin's own storage directory.
 *
 * ## The queue is a file, not a cursor field
 *
 * A resumable walk needs somewhere to keep the directories it has not visited
 * yet.  Putting that in the cursor would put an unbounded list in a database
 * column — a deep `node_modules` tree alone can be thousands of directories
 * wide.  So the queue is `dirs.jsonl`, appended to as directories are
 * discovered and read forward from a byte offset.  The cursor holds three
 * numbers and nothing else.
 *
 * ## The resume boundary, in the same shape as DatabaseStage's
 *
 * StageRunner persists the cursor after the work it describes, so a crash
 * costs a repeated step rather than a skipped one.  Repeating a step here
 * means re-appending both the files it found and the directories it
 * discovered.  So the cursor carries the byte length of each file, and every
 * resume truncates both back before writing anything.  Bytes past the
 * persisted length are bytes the cursor does not account for.
 */
final class FileScanStage implements Stage {

	public const ID = 'files';

	public const OUTPUT = 'files.jsonl';

	/**
	 * What the scan learned, for the stages that come after it.
	 *
	 * Three numbers no later stage can recover cheaply: the file count, the
	 * total byte size, and how many symlinks were passed over.  Preflight needs
	 * the bytes to gate on a measurement rather than a guess, and the manifest
	 * needs the link count, because an archive missing a media library should
	 * say so on its face rather than being discovered light a year later.
	 */
	public const SUMMARY = 'scan-summary.json';

	private const QUEUE = 'dirs.jsonl';

	/**
	 * Prefix on every queue line, so the scan root — the empty string — has a
	 * line that is not blank.
	 */
	private const QUEUE_MARKER = 'd:';

	/**
	 * @param string|null $root Absolute scan root, or null to use ABSPATH.
	 */
	public function __construct(
		private readonly StorageLocator $storage,
		private readonly ?SettingsRepository $settings = null,
		private readonly ?string $root = null
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Scanning files', 'fiction-drafts' );
	}

	/**
	 * A job that copies no files at all never enters this stage.
	 *
	 * That is `DATABASE_ONLY` for the presets, and a `CUSTOM` job with neither
	 * core nor uploads ticked.
	 */
	public function appliesTo( BackupJob $job ): bool {
		return $job->includesCore() || $job->includesUploads();
	}

	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		$workingDir = $this->workingDir( $job );
		$filesPath  = $workingDir . '/' . self::OUTPUT;
		$queuePath  = $workingDir . '/' . self::QUEUE;

		$scope  = ScanScope::forJob( $job, $this->settings?->get() );
		$walker = new FileWalker( [ $this->storage->baseDir() ] );
		$root   = $this->root();

		$resuming = ! $cursor->isStart() && is_file( $filesPath ) && is_file( $queuePath );

		if ( ! $resuming ) {
			$this->seed( $queuePath, $filesPath, $scope, $job, $root );
		}

		$filesBytes = $resuming ? $cursor->getInt( 'files' ) : filesize( $filesPath );
		$queueBytes = $resuming ? $cursor->getInt( 'dirs' ) : filesize( $queuePath );
		$read       = $resuming ? $cursor->getInt( 'read' ) : 0;
		$links      = $resuming ? $cursor->getInt( 'links' ) : 0;

		// The queue is read from `read` and appended to at `dirs`, both into
		// one file.  A read position past the append position would mean
		// reading bytes the resume is about to discard, so the two numbers
		// have exactly one legal relationship.
		if ( $read > (int) $queueBytes ) {
			throw new RuntimeException(
				sprintf( 'The scan queue cursor reads at %d but is only %d bytes long.', $read, (int) $queueBytes )
			);
		}

		$files  = $this->openAt( $filesPath, (int) $filesBytes );
		$append = $this->openAt( $queuePath, (int) $queueBytes );
		$reader = $this->openForReading( $queuePath );

		fseek( $reader, $read );

		$processed = 0;

		// One directory before the clock is consulted, so a budget that is
		// already exhausted still makes forward progress — StageRunner fails
		// any unfinished step that hands back an unchanged cursor.
		do {
			$line = fgets( $reader );

			if ( false === $line ) {
				fclose( $reader );
				fclose( $append );
				fclose( $files );

				$summary = $this->summarise( $filesPath, $links );

				$this->writeSummary( $workingDir, $summary );

				return StageResult::complete( $processed, $summary['files'] );
			}

			$read      = (int) ftell( $reader );
			$directory = $this->decodeQueueLine( $line );

			if ( null === $directory ) {
				continue;
			}

			$listing = $walker->children( $root, $directory, $scope->exclusions() );

			$links += $listing['skipped'];

			foreach ( $listing['files'] as $file ) {
				fwrite( $files, $this->encodeEntry( $file['path'], $file['size'] ) );
				++$processed;
			}

			foreach ( $listing['dirs'] as $child ) {
				fwrite( $append, $this->encodeQueueLine( $child ) );
			}
		} while ( ! $budget->exhausted() );

		fflush( $files );
		fflush( $append );

		$cursor = StageCursor::fromArray(
			[
				'read'  => $read,
				'dirs'  => $this->lengthOf( $append, $queuePath ),
				'files' => $this->lengthOf( $files, $filesPath ),
				'links' => $links,
			]
		);

		fclose( $reader );
		fclose( $append );
		fclose( $files );

		// Null rather than a count: the scan genuinely does not know its own
		// size until it is done, and reporting a partial count as the total
		// would show a progress bar that reached 100% and kept going.
		return StageResult::incomplete( $cursor, $processed, null );
	}

	/**
	 * Start a fresh scan: an empty list, and a queue holding the job's roots.
	 */
	private function seed( string $queuePath, string $filesPath, ScanScope $scope, BackupJob $job, string $root ): void {
		$queue = '';

		foreach ( $scope->roots() as $scanRoot ) {
			$queue .= $this->encodeQueueLine( $scanRoot );
		}

		file_put_contents( $queuePath, $queue ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see the class docblock.
		file_put_contents( $filesPath, $this->externalWpConfig( $job, $root ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see the class docblock.
	}

	/**
	 * The `wp-config.php` line for an install that keeps it above the root.
	 *
	 * WordPress looks one directory up when the file is not beside `wp-load.php`,
	 * and that layout is common on shared hosting precisely because the file is
	 * then outside the document root.  The walk cannot reach it, so when — and
	 * only when — the job opted in, it is written directly, with an absolute
	 * source path so the archive stage needs no special case for it.
	 */
	private function externalWpConfig( BackupJob $job, string $root ): string {
		if ( ! $job->includesWpConfig() ) {
			return '';
		}

		if ( is_file( $root . '/wp-config.php' ) ) {
			return '';
		}

		$external = dirname( $root ) . '/wp-config.php';

		if ( ! is_file( $external ) ) {
			return '';
		}

		$size = filesize( $external );

		return $this->encodeEntry( 'wp-config.php', false === $size ? 0 : $size, $external );
	}

	/**
	 * One `files.jsonl` line.
	 *
	 * `a` is present only when the file is not at `$root/$path`, which today
	 * means exactly one file.  Keeping it a general key rather than a
	 * `wp-config.php` special case means the archive stage stays ignorant of
	 * why a source path might differ.
	 *
	 * @throws RuntimeException When the entry cannot be represented at all.
	 */
	private function encodeEntry( string $path, int $size, ?string $absolute = null ): string {
		$entry = [
			'p' => $path,
			's' => $size,
		];

		// A filename that is not valid UTF-8 makes json_encode() return false,
		// and `(string) false` is an empty line — the file would be dropped
		// from the backup with nothing to show for it, and every
		// archive-matches-the-list check would still pass, because it was
		// never in the list.  Latin-1 names in `wp-content/uploads` are
		// ordinary on a site migrated from an older host, so this is the
		// common case, not the exotic one.  `b` marks a base64 path; a zip
		// entry name is a byte string, so the original name survives.
		if ( ! self::isUtf8( $path ) ) {
			$entry['p'] = base64_encode( $path );
			$entry['b'] = 1;
		}

		if ( null !== $absolute ) {
			$entry['a'] = self::isUtf8( $absolute ) ? $absolute : base64_encode( $absolute );
		}

		$encoded = wp_json_encode( $entry );

		if ( false === $encoded ) {
			// Nothing left that could have failed, but a silently dropped file
			// is the one outcome this method must never produce.
			throw new RuntimeException( sprintf( 'Could not record "%s" in the file list.', $path ) );
		}

		return $encoded . "\n";
	}

	private static function isUtf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}

	private function encodeQueueLine( string $directory ): string {
		// Base64 unconditionally, because a directory name can be any byte
		// string and a queue line that fails to encode drops a whole subtree.
		// The leading marker is what makes the scan root representable: it is
		// the empty string, whose base64 is also empty, and a bare blank line
		// is indistinguishable from the end of the file.
		return self::QUEUE_MARKER . base64_encode( $directory ) . "\n";
	}

	/**
	 * A queue line back to a directory, or null when it is not one.
	 */
	private function decodeQueueLine( string $line ): ?string {
		$trimmed = rtrim( $line, "\r\n" );

		if ( ! str_starts_with( $trimmed, self::QUEUE_MARKER ) ) {
			return null;
		}

		$decoded = base64_decode( substr( $trimmed, strlen( self::QUEUE_MARKER ) ), true );

		return false === $decoded ? null : $decoded;
	}

	/**
	 * Open a file for appending, discarding anything past $length.
	 *
	 * @return resource
	 * @throws RuntimeException When the file cannot be opened for writing.
	 */
	private function openAt( string $path, int $length ) {
		// ftruncate() to a length greater than the file's own pads it with NUL
		// bytes instead of failing.  A crash that lost buffered writes leaves
		// the file shorter than the cursor claims, and padding it would put
		// NULs where a JSON line should be.  Refusing is the only safe answer:
		// the resume position no longer describes anything on disk.
		$size = filesize( $path );

		if ( false === $size || $size < $length ) {
			throw new RuntimeException(
				sprintf(
					'"%s" is %s bytes but the cursor resumes at %d.',
					basename( $path ),
					false === $size ? 'unreadable' : (string) $size,
					$length
				)
			);
		}

		$handle = fopen( $path, 'r+b' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not open "%s" for writing.', basename( $path ) ) );
		}

		ftruncate( $handle, $length );
		fseek( $handle, $length );

		return $handle;
	}

	/**
	 * @return resource
	 * @throws RuntimeException When the queue cannot be read.
	 */
	private function openForReading( string $path ) {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not open "%s" for reading.', basename( $path ) ) );
		}

		return $handle;
	}

	/**
	 * @param resource $handle An open write handle.
	 */
	private function lengthOf( $handle, string $path ): int {
		$position = ftell( $handle );

		if ( false !== $position ) {
			return $position;
		}

		$size = filesize( $path );

		return false === $size ? 0 : $size;
	}

	/**
	 * Lines in `files.jsonl`, counted by streaming rather than by reading it in.
	 */
	/**
	 * One pass over the finished list: how many files, and how many bytes.
	 *
	 * The stage already had to count the lines to report a total, so summing
	 * the sizes in the same pass costs nothing.  Doing it here rather than in
	 * PrepareStage keeps the only full read of `files.jsonl` in the stage that
	 * wrote it.
	 *
	 * @return array{files: int, bytes: int, symlinks: int}
	 */
	private function summarise( string $path, int $skippedSymlinks ): array {
		$summary = [
			'files'    => 0,
			'bytes'    => 0,
			'symlinks' => $skippedSymlinks,
		];

		if ( ! is_file( $path ) ) {
			return $summary;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return $summary;
		}

		while ( true ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			++$summary['files'];

			$decoded = json_decode( trim( $line ), true );

			if ( is_array( $decoded ) && isset( $decoded['s'] ) && is_numeric( $decoded['s'] ) ) {
				$summary['bytes'] += (int) $decoded['s'];
			}
		}

		fclose( $handle );

		return $summary;
	}


	/**
	 * @param array{files: int, bytes: int, symlinks: int} $summary Scan totals.
	 */
	private function writeSummary( string $workingDir, array $summary ): void {
		$json = wp_json_encode( $summary );

		if ( false === $json ) {
			return;
		}

		file_put_contents( $workingDir . '/' . self::SUMMARY, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see the class docblock.
	}

	/**
	 * The absolute path every entry in `files.jsonl` is relative to.
	 *
	 * Injectable for the same reason StorageLocator's root is: a test needs a
	 * tree it built itself, and ABSPATH is a constant that cannot vary between
	 * two tests in one process.
	 */
	private function root(): string {
		return untrailingslashit( $this->root ?? ABSPATH );
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
