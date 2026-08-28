<?php

declare( strict_types=1 );

namespace FictionDrafts\Database;

/**
 * How a column's values must be written into an INSERT.
 *
 * Three kinds, because there are exactly three answers: quote it, hex it, or
 * leave it bare.  Spec §6.4's table is the whole of this type.
 */
enum ColumnKind {

	case Text;

	case Numeric;

	case Binary;

	/**
	 * Classify from the `Type` column of `SHOW COLUMNS`, e.g. `varchar(255)`.
	 *
	 * `bit` is treated as binary rather than numeric on purpose: MySQL renders
	 * a BIT value as raw bytes, so quoting or printing it bare both corrupt it,
	 * while a hex literal round-trips exactly.
	 */
	public static function fromSqlType( string $type ): self {
		$base = strtolower( trim( $type ) );

		if ( str_contains( $base, 'blob' ) || str_starts_with( $base, 'binary' ) || str_starts_with( $base, 'varbinary' ) || str_starts_with( $base, 'bit' ) ) {
			return self::Binary;
		}

		$numeric = [ 'tinyint', 'smallint', 'mediumint', 'bigint', 'int', 'integer', 'decimal', 'numeric', 'float', 'double', 'real', 'year' ];

		foreach ( $numeric as $prefix ) {
			if ( str_starts_with( $base, $prefix ) ) {
				return self::Numeric;
			}
		}

		return self::Text;
	}
}
