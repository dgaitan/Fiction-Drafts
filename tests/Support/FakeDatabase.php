<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Database\DatabaseConnection;

/**
 * A `DatabaseConnection` backed by arrays.
 *
 * It exists so the rules in spec §6.4 can be asserted exactly — a NULL is
 * `NULL` and not `''`, an empty blob is `''` and not `0x` — without a MySQL
 * server in the loop.  The round-trip against real MySQL 8 proves the assembly;
 * these prove the rules.
 *
 * `prepare()` and `escLike()` reproduce what `wpdb` does rather than stubbing
 * them out, because the escaping *is* what several of the criteria are about.
 */
final class FakeDatabase implements DatabaseConnection {

	/**
	 * @var array<int, string> Every query this connection was asked to run.
	 */
	public array $log = [];

	public int $releases = 0;

	/**
	 * @var array<int, string> Every time zone this connection was switched to.
	 */
	public array $timeZones = [];

	private string $timeZone = 'SYSTEM';

	/**
	 * @var array<string, array{columns: array<int, array<string, string|null>>, rows: array<int, array<string, string|null>>, create: string, type: string}>
	 */
	private array $tables = [];

	public function __construct(
		private readonly string $prefix = 'wp_',
		private readonly string $basePrefix = 'wp_',
		private readonly bool $multisite = false
	) {}

	/**
	 * @param array<int, array<string, string|null>> $columns `SHOW COLUMNS` rows: Field, Type, Key, Extra.
	 * @param array<int, array<string, string|null>> $rows    The table's data.
	 */
	public function addTable( string $name, array $columns, array $rows = [], string $create = '', string $type = 'BASE TABLE' ): void {
		$this->tables[ $name ] = [
			'columns' => $columns,
			'rows'    => $rows,
			'create'  => '' === $create ? 'CREATE TABLE `' . $name . '` (`id` bigint)' : $create,
			'type'    => $type,
		];
	}

	public function prefix(): string {
		return $this->prefix;
	}

	public function basePrefix(): string {
		return $this->basePrefix;
	}

	public function isMultisite(): bool {
		return $this->multisite;
	}

	public function escLike( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$index = 0;

		return (string) preg_replace_callback(
			'/%[sd]/',
			function ( array $match ) use ( &$index, $args ): string {
				$value = $args[ $index ] ?? '';
				++$index;

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}

				return "'" . self::escape( (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * @return array<int, array<string, string|null>>
	 */
	public function results( string $query ): array {
		$this->log[] = $query;

		if ( 1 === preg_match( '/^SHOW COLUMNS FROM `(.+)`$/', $query, $match ) ) {
			return $this->tables[ $match[1] ]['columns'] ?? [];
		}

		if ( 1 === preg_match( '/FROM `([^`]+)`/', $query, $match ) ) {
			$rows   = $this->tables[ $match[1] ]['rows'] ?? [];
			$limit  = 1 === preg_match( '/LIMIT (\d+)/', $query, $l ) ? (int) $l[1] : count( $rows );
			$offset = 1 === preg_match( '/OFFSET (\d+)/', $query, $o ) ? (int) $o[1] : 0;

			return array_slice( $rows, $offset, $limit );
		}

		return [];
	}

	/**
	 * @return array<int, array<int, string|null>>
	 */
	public function rows( string $query ): array {
		$this->log[] = $query;

		if ( str_starts_with( $query, 'SHOW FULL TABLES' ) ) {
			$out = [];

			foreach ( $this->tables as $name => $table ) {
				$out[] = [ $name, $table['type'] ];
			}

			return $out;
		}

		if ( 1 === preg_match( '/^SHOW CREATE TABLE `(.+)`$/', $query, $match ) ) {
			return [ [ $match[1], $this->tables[ $match[1] ]['create'] ?? '' ] ];
		}

		return [];
	}

	public function var( string $query ): ?string {
		$this->log[] = $query;

		$total = 0;

		foreach ( $this->tables as $table ) {
			$total += count( $table['rows'] );
		}

		return (string) $total;
	}

	public function timeZone(): string {
		return $this->timeZone;
	}

	public function setTimeZone( string $value ): void {
		$this->timeZone    = $value;
		$this->timeZones[] = $value;
	}

	public function release(): void {
		++$this->releases;
	}

	/**
	 * What `mysqli_real_escape_string` does, for the characters that matter.
	 */
	private static function escape( string $value ): string {
		return str_replace(
			[ '\\', "'", '"', "\n", "\r", "\x00", "\x1a" ],
			[ '\\\\', "\\'", '\\"', '\\n', '\\r', '\\0', '\\Z' ],
			$value
		);
	}
}
