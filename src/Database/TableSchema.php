<?php

declare( strict_types=1 );

namespace FictionDrafts\Database;

/**
 * What one table's columns are, and which of them an INSERT may name.
 *
 * Two things this exists to prevent, both of which produce a dump that looks
 * fine and fails on import:
 *
 * 1. **Generated columns.** MySQL 5.7+ allows `VIRTUAL GENERATED` and
 *    `STORED GENERATED` columns, and refuses any INSERT that supplies a value
 *    for one.  A positional `INSERT INTO t VALUES (…)` supplies values for
 *    every column, so a single generated column anywhere on the site makes the
 *    whole dump unimportable.  Naming the insertable columns explicitly is the
 *    fix, and it also survives a target whose column order differs.
 *
 * 2. **`DEFAULT_GENERATED` is not a generated column.** It is what `Extra`
 *    says for `DEFAULT CURRENT_TIMESTAMP`, and it is extremely common — this
 *    plugin's own development database has ten of them and zero real generated
 *    columns.  A substring test for "GENERATED" would silently drop ten
 *    perfectly ordinary timestamp columns out of the backup.
 */
final class TableSchema {

	/**
	 * @param array<int, string>        $insertable  Column names an INSERT may name, in table order.
	 * @param array<string, ColumnKind> $kinds       Column name to how its values are written.
	 * @param array<int, string>        $primaryKeys Primary-key columns, in key order.
	 * @param array<int, string>        $sortable    Columns cheap enough to order a whole table by.
	 */
	private function __construct(
		private readonly array $insertable,
		private readonly array $kinds,
		private readonly array $primaryKeys,
		private readonly array $sortable
	) {}

	/**
	 * Build from the rows of `SHOW COLUMNS FROM {table}`.
	 *
	 * @param array<int, array<string, string|null>> $columns Raw SHOW COLUMNS output.
	 */
	public static function fromShowColumns( array $columns ): self {
		$insertable  = [];
		$kinds       = [];
		$primaryKeys = [];
		$sortable    = [];

		foreach ( $columns as $column ) {
			$field = (string) ( $column['Field'] ?? '' );

			if ( '' === $field ) {
				continue;
			}

			$type            = (string) ( $column['Type'] ?? '' );
			$kinds[ $field ] = ColumnKind::fromSqlType( $type );

			if ( 'PRI' === ( $column['Key'] ?? '' ) ) {
				$primaryKeys[] = $field;
			}

			if ( self::isGenerated( (string) ( $column['Extra'] ?? '' ) ) ) {
				continue;
			}

			$insertable[] = $field;

			if ( self::isSortable( $type ) ) {
				$sortable[] = $field;
			}
		}

		return new self( $insertable, $kinds, $primaryKeys, $sortable );
	}

	/**
	 * Is this column cheap enough to put in an ORDER BY over a whole table?
	 *
	 * BLOB and TEXT columns are not: MySQL sorts them by a prefix and copies
	 * them through the sort buffer, which on a `longtext` table turns an
	 * ordering into the most expensive part of the dump.  They are only ever
	 * used as a last resort for a table with no key at all, and leaving them
	 * out of that resort is the right trade.
	 */
	private static function isSortable( string $type ): bool {
		$base = strtolower( trim( $type ) );

		return ! str_contains( $base, 'blob' ) && ! str_contains( $base, 'text' ) && ! str_starts_with( $base, 'json' );
	}

	/**
	 * Is this column computed by the server, and therefore un-insertable?
	 */
	private static function isGenerated( string $extra ): bool {
		$normalised = strtoupper( $extra );

		return str_contains( $normalised, 'VIRTUAL GENERATED' ) || str_contains( $normalised, 'STORED GENERATED' );
	}

	/**
	 * @return array<int, string>
	 */
	public function insertableColumns(): array {
		return $this->insertable;
	}

	public function hasInsertableColumns(): bool {
		return [] !== $this->insertable;
	}

	public function kindOf( string $column ): ColumnKind {
		return $this->kinds[ $column ] ?? ColumnKind::Text;
	}

	/**
	 * The columns to order batched reads by.
	 *
	 * This is a correctness feature, not a tidiness one.  `LIMIT n OFFSET m`
	 * has no guaranteed order, and this dump reads its batches from separate
	 * HTTP requests, minutes apart, on a site that is still taking writes.  A
	 * window that moves between two requests loses rows or repeats them, and on
	 * a table without a primary key the import raises no error either way.
	 *
	 * Three cases, in descending order of how good the answer is:
	 *
	 * 1. **A primary key, of any width.** Ordering by all of its columns is
	 *    both total and indexed, so it costs nothing. Ordering by the *first*
	 *    column of a composite key would not be total — which is why this
	 *    returns the whole key rather than one column of it. `term_relationships`
	 *    has a composite key and ships in every WordPress install, so this case
	 *    is not hypothetical.
	 * 2. **No primary key, but sortable columns.** Ordering by all of them is
	 *    total in the only sense that matters: two rows that agree in every
	 *    column are interchangeable, so a window that swaps them changes
	 *    nothing observable. It costs a filesort.
	 * 3. **Nothing sortable.** No order, and the risk is real. There is no
	 *    honest way to do better without a key.
	 *
	 * @return array<int, string>
	 */
	public function orderColumns(): array {
		if ( [] !== $this->primaryKeys ) {
			return $this->primaryKeys;
		}

		return $this->sortable;
	}

	/**
	 * @return array<int, string>
	 */
	public function primaryKeyColumns(): array {
		return $this->primaryKeys;
	}
}
