<?php

declare( strict_types=1 );

namespace FictionDrafts\Database;

/**
 * One value in, one SQL literal out.
 *
 * Spec §6.4's table, and every line of it is a real corruption bug if it is
 * got wrong:
 *
 * | value                       | emitted as                        |
 * |-----------------------------|-----------------------------------|
 * | null                        | `NULL` — unquoted, **not** `''`   |
 * | binary column               | `0x…` hex literal                 |
 * | empty binary column         | `''` — a bare `0x` is a syntax error |
 * | numeric column              | unquoted                          |
 * | non-numeric in numeric column | quoted, so the statement still parses |
 * | invalid UTF-8 in a text column | `0x…` hex literal              |
 * | anything else               | escaped and single-quoted         |
 *
 * The distinction that matters most is the first: MySQL treats `NULL` and `''`
 * as different values, and WordPress reads them differently — a `post_parent`
 * of NULL and of empty string behave differently in exactly the queries nobody
 * tests.  Collapsing them is the classic hand-rolled-dump bug.
 */
final class RowSerializer {

	public function __construct( private readonly DatabaseConnection $db ) {}

	/**
	 * The SQL literal for one column value.
	 */
	public function literal( ?string $value, ColumnKind $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( ColumnKind::Binary === $kind ) {
			return $this->hexOrEmpty( $value );
		}

		if ( ColumnKind::Numeric === $kind ) {
			// A numeric column holding something unparseable is not worth a
			// fatal: quoting it keeps the statement valid and lets MySQL apply
			// its own coercion, which is what the original INSERT did too.
			return is_numeric( $value ) ? $value : $this->quoted( $value );
		}

		// A text column can still hold bytes that are not valid UTF-8 — a
		// latin1 column read through a utf8mb4 connection, or a serialized PHP
		// string carrying raw bytes.  Quoting those produces a file that either
		// fails to import or imports with the bytes replaced.  Hex round-trips.
		if ( ! $this->isValidUtf8( $value ) ) {
			return $this->hexOrEmpty( $value );
		}

		return $this->quoted( $value );
	}

	/**
	 * A whole row, as the parenthesised tuple of an INSERT.
	 *
	 * @param array<string, string|null> $row     One row, keyed by column name.
	 * @param array<int, string>         $columns The insertable columns, in order.
	 */
	public function tuple( array $row, array $columns, TableSchema $schema ): string {
		$literals = [];

		foreach ( $columns as $column ) {
			$literals[] = $this->literal( $row[ $column ] ?? null, $schema->kindOf( $column ) );
		}

		return '(' . implode( ',', $literals ) . ')';
	}

	/**
	 * `0x` followed by nothing is a syntax error, so empty stays `''`.
	 */
	private function hexOrEmpty( string $value ): string {
		return '' === $value ? "''" : '0x' . bin2hex( $value );
	}

	/**
	 * Escaped and single-quoted, by the same code path every other query uses.
	 */
	private function quoted( string $value ): string {
		return $this->db->prepare( '%s', $value );
	}

	/**
	 * `preg_match` with the `u` modifier fails on invalid UTF-8, and unlike
	 * `mb_check_encoding` it is always available.
	 */
	private function isValidUtf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}
}
