<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup\Stages;

use FictionDrafts\Contracts\Stage;
use FictionDrafts\Database\DatabaseConnection;
use FictionDrafts\Database\RowSerializer;
use FictionDrafts\Database\SqlDumper;
use FictionDrafts\Database\TableEnumerator;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use FictionDrafts\Domain\TimeBudget;
use FictionDrafts\Storage\StorageLocator;
use RuntimeException;

/**
 * Writes `database.sql`, a batch at a time, across as many requests as it takes.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 *
 * WP_Filesystem has no streaming append: its write() takes the whole file's
 * contents as a string.  Using it here would mean holding a multi-gigabyte
 * dump in memory to add five hundred rows to the end of it, which is the exact
 * failure this stage exists to avoid.  The paths are all inside the plugin's
 * own storage directory, so the credentials case WP_Filesystem exists for does
 * not arise.
 *
 * ## The resume boundary
 *
 * `StageRunner` persists the cursor *after* the work it describes, so a crash
 * costs a repeated batch rather than a skipped one.  For a stage that returns
 * rows to a caller that is fine.  For a stage that appends to a file it is
 * not: the repeated batch is appended a second time, and the result is a dump
 * with duplicated INSERTs that still imports without error.  On a table with a
 * primary key the import fails loudly; on one without, the copy is silently
 * wrong.
 *
 * So the cursor carries the output's byte length as well as the row offset,
 * and every resume truncates `database.sql` back to that length before writing
 * anything.  Bytes written after the last persisted cursor are, by definition,
 * bytes whose rows the cursor does not account for — discarding them is what
 * makes "repeat a batch" harmless.
 */
final class DatabaseStage implements Stage {

	public const ID = 'database';

	/**
	 * Public because PrepareStage measures this file to decide whether there
	 * is room for the archive, and ArchiveStage names it as the first entry.
	 * One constant beats three string literals that can drift apart.
	 */
	public const OUTPUT = 'database.sql';

	private const TABLES = 'tables.json';

	public function __construct(
		private readonly DatabaseConnection $db,
		private readonly StorageLocator $storage
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Exporting the database', 'fiction-drafts' );
	}

	public function appliesTo( BackupJob $job ): bool {
		return $job->includesDatabase();
	}

	/**
	 * Read every batch in UTC, and put the session back afterwards.
	 *
	 * A `timestamp` column is stored in UTC and converted to and from the
	 * session time zone on every read and write.  Read in Europe/Madrid and
	 * imported on a host in UTC, every one of them shifts by two hours — the
	 * file imports without a warning and the data is wrong.  Reading in UTC and
	 * emitting `SET TIME_ZONE='+00:00'` in the header makes both ends agree.
	 *
	 * The zone is restored in a `finally` because this runs inside a WordPress
	 * request that has other work to do after the step returns.
	 */
	public function run( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		$previousZone = $this->db->timeZone();
		$this->db->setTimeZone( '+00:00' );

		try {
			return $this->dump( $job, $cursor, $budget );
		} finally {
			$this->db->setTimeZone( $previousZone );
		}
	}

	private function dump( BackupJob $job, StageCursor $cursor, TimeBudget $budget ): StageResult {
		$workingDir = $this->workingDir( $job );
		$path       = $workingDir . '/' . self::OUTPUT;

		$tables = $this->tables( $workingDir );
		$dumper = $this->dumper( $job, $tables );

		$resuming = ! $cursor->isStart() && is_file( $path );

		$index      = $resuming ? $cursor->getInt( 'index' ) : 0;
		$offset     = $resuming ? $cursor->getInt( 'offset' ) : 0;
		$schemaDone = $resuming && 1 === $cursor->getInt( 'schema' );

		$handle = $this->open( $path, $resuming ? $cursor->getInt( 'bytes' ) : null );

		if ( ! $resuming ) {
			fwrite( $handle, $dumper->header( $this->meta( $job ) ) );
		}

		$batchRows   = $dumper->batchRows();
		$processed   = 0;
		$schema      = null;
		$schemaTable = null;

		// One unit of work happens before the clock is consulted.  A budget
		// that is already exhausted must still make progress, or the runner
		// re-enqueues a step that can never finish — see StageRunner's note on
		// the forward-progress rule.
		do {
			if ( $index >= count( $tables ) ) {
				fwrite( $handle, $dumper->footer() );
				fclose( $handle );

				return StageResult::complete( $processed, $dumper->estimatedRows() );
			}

			$table = $tables[ $index ];

			if ( ! $schemaDone ) {
				fwrite( $handle, $dumper->schemaBlock( $table ) );

				$schemaDone = true;
				$offset     = 0;

				continue;
			}

			if ( $schemaTable !== $table || null === $schema ) {
				$schema      = $dumper->schemaFor( $table );
				$schemaTable = $table;
			}

			$batch = $dumper->insertBatch( $table, $schema, $offset, $batchRows );

			if ( '' !== $batch['sql'] ) {
				fwrite( $handle, $batch['sql'] );
			}

			$offset    += $batch['rows'];
			$processed += $batch['rows'];

			// A short read is the end of the table.  Taking it as the signal
			// costs nothing, where a COUNT(*) per table costs a full index scan
			// on every table on the site.
			if ( $batch['rows'] < $batchRows ) {
				++$index;
				$schemaDone = false;
				$offset     = 0;
			}
		} while ( ! $budget->exhausted() );

		fflush( $handle );
		$bytes = ftell( $handle );
		fclose( $handle );

		return StageResult::incomplete(
			StageCursor::fromArray(
				[
					'index'  => $index,
					'offset' => $offset,
					'schema' => $schemaDone ? 1 : 0,
					'bytes'  => false === $bytes ? filesize( $path ) : $bytes,
				]
			),
			$processed,
			$dumper->estimatedRows()
		);
	}

	/**
	 * Open the dump for writing, discarding anything past the persisted length.
	 *
	 * @param  int|null $truncateTo Byte length to resume from, or null to start fresh.
	 * @return resource
	 * @throws RuntimeException When the dump file cannot be opened for writing.
	 */
	private function open( string $path, ?int $truncateTo ) {
		// ftruncate() to a length *greater* than the file pads with NUL bytes
		// rather than failing.  If a crash lost buffered writes the file can be
		// shorter than the cursor says, and padding it would put a run of NULs
		// in the middle of the dump — SQL that imports as far as the padding
		// and then stops.  A shorter file than the cursor accounts for means
		// the resume position is not recoverable, so say so.
		if ( null !== $truncateTo ) {
			$size = filesize( $path );

			if ( false === $size || $size < $truncateTo ) {
				throw new RuntimeException(
					sprintf(
						'"%s" is %s bytes but the cursor resumes at %d.',
						self::OUTPUT,
						false === $size ? 'unreadable' : (string) $size,
						$truncateTo
					)
				);
			}
		}

		$handle = fopen( $path, null === $truncateTo ? 'wb' : 'r+b' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not open "%s" for writing.', self::OUTPUT ) );
		}

		if ( null !== $truncateTo ) {
			ftruncate( $handle, $truncateTo );
			fseek( $handle, $truncateTo );
		}

		return $handle;
	}

	/**
	 * This job's table list, resolved once and then read back from disk.
	 *
	 * Re-enumerating on every step would let a table created mid-backup shift
	 * every index after it, and the cursor addresses tables by index.  The list
	 * lives in the working directory rather than on the job row for the same
	 * reason `files.jsonl` will: it is derived data of unbounded size, and the
	 * database is not where that belongs.
	 *
	 * @return array<int, string>
	 */
	private function tables( string $workingDir ): array {
		$path = $workingDir . '/' . self::TABLES;

		if ( is_file( $path ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true );

			if ( is_array( $decoded ) ) {
				return array_values( array_filter( $decoded, 'is_string' ) );
			}
		}

		$tables = ( new TableEnumerator( $this->db ) )->forSite();

		file_put_contents( $path, (string) wp_json_encode( $tables ) );

		return $tables;
	}

	/**
	 * @param array<int, string> $tables The allow-list for this job.
	 */
	private function dumper( BackupJob $job, array $tables ): SqlDumper {
		return new SqlDumper(
			$this->db,
			new RowSerializer( $this->db ),
			$tables,
			false !== $job->option( BackupJob::OPTION_EXCLUDE_TRANSIENTS, true )
		);
	}

	/**
	 * The comment header's contents — what site this copy came from.
	 *
	 * @return array<string, string>
	 */
	private function meta( BackupJob $job ): array {
		return [
			// The job's own creation time, not the clock at the moment this
			// line is written.  A dump that restarts from the beginning — the
			// file went missing between two steps — would otherwise carry a
			// header stamped minutes after the backup it belongs to, and the
			// same job would produce two different files.
			'Generated' => null === $job->createdAt ? gmdate( 'c' ) : $job->createdAt . ' UTC',
			'Site'      => (string) get_site_url(),
			'Prefix'    => $this->db->prefix(),
			'WordPress' => (string) get_bloginfo( 'version' ),
			'PHP'       => PHP_VERSION,
			'Profile'   => $job->profile->value,
		];
	}

	private function workingDir( BackupJob $job ): string {
		$this->storage->ensure();

		$dir = $this->storage->workingDir( $job->uuid );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Could not create the job working directory.' );
		}

		return $dir;
	}
}
