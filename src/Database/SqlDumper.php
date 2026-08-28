<?php

declare( strict_types=1 );

namespace FictionDrafts\Database;

use InvalidArgumentException;

/**
 * Turns tables into importable SQL text, a piece at a time.
 *
 * The dumper never decides *when* to stop — that is the stage's job — and it
 * never writes to disk.  It answers four questions: what does the file open
 * with, what does one table's schema block look like, what does one batch of
 * its rows look like, and what does the file close with.  Keeping it free of
 * both the clock and the filesystem is what makes every rule in spec §6.4
 * testable without a running backup.
 *
 * ## The allow-list boundary
 *
 * Every table name that reaches a SQL string here has to be one of the names
 * this object was constructed with, and those come from `TableEnumerator`,
 * which got them from the server.  A name from anywhere else throws.  This is
 * the single place the containment is enforced, so there is one thing to audit
 * rather than one per query.
 */
final class SqlDumper {

	/**
	 * Cap on one INSERT statement, in bytes.
	 *
	 * Not a memory concern — a byte cap on the *statement* exists because the
	 * host that imports this file is not the host that wrote it.  MySQL rejects
	 * any statement larger than its `max_allowed_packet`, which defaults to
	 * 64 MiB on 8.0 but is commonly 4 MiB or even 1 MiB on shared hosting.  A
	 * 500-row batch of `longtext` can pass all of those.  One megabyte is under
	 * every default that ships.
	 */
	public const DEFAULT_MAX_INSERT_BYTES = 1048576;

	public const DEFAULT_BATCH_ROWS = 500;

	public const FILTER_BATCH_ROWS = 'fiction_drafts/batch_rows';

	public const FILTER_MAX_INSERT_BYTES = 'fiction_drafts/max_insert_bytes';

	/**
	 * @var array<string, true> Allow-listed table names, as a set.
	 */
	private readonly array $allowed;

	/**
	 * @param array<int, string> $tables The allow-list, from TableEnumerator.
	 */
	public function __construct(
		private readonly DatabaseConnection $db,
		private readonly RowSerializer $serializer,
		array $tables,
		private readonly bool $excludeTransients = true
	) {
		$this->allowed = array_fill_keys( $tables, true );
	}

	/**
	 * The file header — spec §6.4.
	 *
	 * @param array<string, string> $meta Descriptive values for the comment block.
	 */
	public function header( array $meta ): string {
		$lines = [ '-- Fiction Drafts export' ];

		foreach ( $meta as $key => $value ) {
			$lines[] = '-- ' . $key . ': ' . str_replace( [ "\r", "\n" ], ' ', $value );
		}

		$skipped = $this->skippedObjects();

		if ( [] !== $skipped ) {
			// Say what is not in the file.  A dropped trigger is the worst of
			// these: the import succeeds, the site works, and data quietly
			// stops being maintained.  Naming it is the least this can do.
			$lines[] = '-- NOT included in this export: ' . implode( ', ', $skipped ) . '.';
		}

		$lines[] = '';
		$lines[] = 'SET NAMES utf8mb4;';
		$lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
		$lines[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
		// The rows below were read with the session time zone set to UTC, so
		// the importing session has to be in UTC too or every `timestamp`
		// column shifts by the difference between the two hosts' zones.
		$lines[] = "SET TIME_ZONE='+00:00';";
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Database objects this export does not carry, described for the header.
	 *
	 * Views, triggers, routines, and events are not tables, and a row-oriented
	 * dump has nothing to say about them.  v1 does not export them; what it
	 * will not do is let them disappear silently.
	 *
	 * @return array<int, string>
	 */
	public function skippedObjects(): array {
		$counts = $this->db->results(
			'SELECT
				( SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'VIEW\' ) AS views,
				( SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ) AS triggers,
				( SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ) AS routines,
				( SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() ) AS events'
		);

		$row   = $counts[0] ?? [];
		$notes = [];

		foreach ( [ 'views', 'triggers', 'routines', 'events' ] as $kind ) {
			$count = (int) ( $row[ $kind ] ?? 0 );

			if ( $count > 0 ) {
				$notes[] = $count . ' ' . ( 1 === $count ? rtrim( $kind, 's' ) : $kind );
			}
		}

		return $notes;
	}

	/**
	 * The file footer, restoring what the header switched off.
	 */
	public function footer(): string {
		return "\nSET FOREIGN_KEY_CHECKS=1;\n";
	}

	/**
	 * `DROP TABLE IF EXISTS` plus the server's own `CREATE TABLE`.
	 *
	 * The CREATE is taken verbatim rather than rebuilt from column metadata:
	 * anything this plugin reconstructed would drift from what MySQL actually
	 * produces — collations, row formats, generated-column expressions, index
	 * prefixes — and the drift would only surface on import.
	 *
	 * @throws InvalidArgumentException When the table is not allow-listed, or the server returns nothing for it.
	 */
	public function schemaBlock( string $table ): string {
		$this->assertAllowed( $table );

		$row = $this->db->rows( 'SHOW CREATE TABLE ' . $this->quoteIdentifier( $table ) );

		$create = (string) ( $row[0][1] ?? '' );

		if ( '' === $create ) {
			throw new InvalidArgumentException( sprintf( 'SHOW CREATE TABLE returned nothing for "%s".', $table ) );
		}

		return "\n--\n-- Table: " . $table . "\n--\n"
			. 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier( $table ) . ";\n"
			. $create . ";\n";
	}

	public function schemaFor( string $table ): TableSchema {
		$this->assertAllowed( $table );

		return TableSchema::fromShowColumns(
			$this->db->results( 'SHOW COLUMNS FROM ' . $this->quoteIdentifier( $table ) )
		);
	}

	/**
	 * One batch of rows, as one or more complete INSERT statements.
	 *
	 * Returns the SQL and the number of rows actually read.  A count lower than
	 * the limit means the table is exhausted; that is how the stage knows to
	 * move on, and it costs nothing extra, where a `COUNT(*)` per table would.
	 *
	 * @return array{sql: string, rows: int}
	 */
	public function insertBatch( string $table, TableSchema $schema, int $offset, int $limit ): array {
		$this->assertAllowed( $table );

		if ( ! $schema->hasInsertableColumns() ) {
			return [
				'sql'  => '',
				'rows' => 0,
			];
		}

		$columns = $schema->insertableColumns();
		$rows    = $this->db->results( $this->selectFor( $table, $columns, $schema, $offset, $limit ) );

		if ( [] === $rows ) {
			$this->db->release();

			return [
				'sql'  => '',
				'rows' => 0,
			];
		}

		$prefixSql = 'INSERT INTO ' . $this->quoteIdentifier( $table )
			. ' (' . implode( ',', array_map( [ $this, 'quoteIdentifier' ], $columns ) ) . ') VALUES ';

		$maxBytes   = $this->maxInsertBytes();
		$statements = [];
		$tuples     = [];
		$size       = 0;

		foreach ( $rows as $row ) {
			$tuple = $this->serializer->tuple( $row, $columns, $schema );

			if ( [] !== $tuples && $size + strlen( $tuple ) > $maxBytes ) {
				$statements[] = $prefixSql . implode( ',', $tuples ) . ";\n";
				$tuples       = [];
				$size         = 0;
			}

			$tuples[] = $tuple;
			$size    += strlen( $tuple ) + 1;
		}

		if ( [] !== $tuples ) {
			$statements[] = $prefixSql . implode( ',', $tuples ) . ";\n";
		}

		$count = count( $rows );

		// Drop the result set before the caller writes anything, so the batch
		// is never held alive alongside the string built from it.
		$this->db->release();

		return [
			'sql'  => implode( '', $statements ),
			'rows' => $count,
		];
	}

	/**
	 * Roughly how many rows the whole dump will write.
	 *
	 * `information_schema.TABLE_ROWS` is an estimate for InnoDB, and that is
	 * the right trade: it is one cheap query for the whole site, where an exact
	 * `COUNT(*)` per table is a full index scan each — minutes of work on a
	 * large site, spent entirely on a progress bar.  The percentage is clamped
	 * to 100 downstream, so an under-estimate cannot show 130%.
	 */
	public function estimatedRows(): int {
		$tables = array_keys( $this->allowed );

		if ( [] === $tables ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );

		$total = $this->db->var(
			$this->db->prepare(
				'SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
				...$tables
			)
		);

		return null === $total ? 0 : (int) $total;
	}

	public function batchRows(): int {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook name is self::FILTER_BATCH_ROWS, 'fiction_drafts/batch_rows'.
		$rows = (int) apply_filters( self::FILTER_BATCH_ROWS, self::DEFAULT_BATCH_ROWS );

		return max( 1, $rows );
	}

	private function maxInsertBytes(): int {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook name is self::FILTER_MAX_INSERT_BYTES, 'fiction_drafts/max_insert_bytes'.
		$bytes = (int) apply_filters( self::FILTER_MAX_INSERT_BYTES, self::DEFAULT_MAX_INSERT_BYTES );

		return max( 1024, $bytes );
	}

	/**
	 * @param array<int, string> $columns Insertable column names.
	 */
	private function selectFor( string $table, array $columns, TableSchema $schema, int $offset, int $limit ): string {
		$select = 'SELECT ' . implode( ',', array_map( [ $this, 'quoteIdentifier' ], $columns ) )
			. ' FROM ' . $this->quoteIdentifier( $table )
			. $this->whereFor( $table );

		// Without an ORDER BY, LIMIT/OFFSET has no guaranteed order — and this
		// dump reads its batches from separate HTTP requests, minutes apart, on
		// a site that is still taking writes.  The ordering is what makes
		// "resume at offset N" mean the same thing on the second request as it
		// did on the first.  TableSchema::orderColumns() explains the three
		// cases; here we just use what it hands back.
		$order = $schema->orderColumns();

		if ( [] !== $order ) {
			$select .= ' ORDER BY ' . implode(
				', ',
				array_map( fn ( string $column ): string => $this->quoteIdentifier( $column ) . ' ASC', $order )
			);
		}

		return $select . $this->db->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
	}

	/**
	 * The row filter for one table, or an empty string when it has none.
	 *
	 * Only two tables have one: transients live in `options` on a single site
	 * and in `sitemeta` on a network.  The clause is built here rather than
	 * accepted from a caller so that no SQL fragment ever crosses this class's
	 * boundary from outside.
	 */
	private function whereFor( string $table ): string {
		if ( ! $this->excludeTransients ) {
			return '';
		}

		if ( $table === $this->db->prefix() . 'options' ) {
			return $this->notLike( 'option_name', [ '_transient_', '_site_transient_' ] );
		}

		if ( $table === $this->db->basePrefix() . 'sitemeta' ) {
			return $this->notLike( 'meta_key', [ '_site_transient_' ] );
		}

		return '';
	}

	/**
	 * @param array<int, string> $prefixes Literal key prefixes to exclude.
	 */
	private function notLike( string $column, array $prefixes ): string {
		$clauses = [];

		foreach ( $prefixes as $prefix ) {
			// esc_like() turns the underscores into LIKE-literal underscores,
			// and prepare() then escapes the backslashes for the string
			// literal.  Skipping either step turns `_transient_` into a
			// wildcard that also matches, say, `xtransientx`.
			$clauses[] = $this->quoteIdentifier( $column ) . ' NOT LIKE '
				. $this->db->prepare( '%s', $this->db->escLike( $prefix ) . '%' );
		}

		return ' WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * Refuse any identifier that did not come from the enumerator.
	 *
	 * @throws InvalidArgumentException Always, when the name is not allow-listed.
	 */
	private function assertAllowed( string $table ): void {
		if ( ! isset( $this->allowed[ $table ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Refusing to dump "%s": it is not in this job\'s table allow-list.', $table )
			);
		}
	}

	/**
	 * Backticks, with any backtick in the name doubled.
	 *
	 * Belt and braces: every name reaching here has already been allow-listed,
	 * so this cannot be the only defence — and it is not meant to be.
	 */
	private function quoteIdentifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}
