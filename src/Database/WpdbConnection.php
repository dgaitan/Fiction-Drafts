<?php

declare( strict_types=1 );

namespace FictionDrafts\Database;

use wpdb;

/**
 * The one file in the dump path that touches $wpdb.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 *
 * Both disables are the same argument Sprint 2 made for JobRepository: a
 * backup reads whole tables, so there is no cache layer that could serve it
 * and caching a full table dump would be worse than not caching it.
 */
final class WpdbConnection implements DatabaseConnection {

	public function __construct( private readonly wpdb $db ) {}

	public static function global(): self {
		global $wpdb;

		return new self( $wpdb );
	}

	public function prefix(): string {
		return $this->db->prefix;
	}

	public function basePrefix(): string {
		return $this->db->base_prefix;
	}

	public function isMultisite(): bool {
		return is_multisite();
	}

	public function escLike( string $text ): string {
		return $this->db->esc_like( $text );
	}

	/**
	 * Prepare a query — and undo wpdb's placeholder escape before returning it.
	 *
	 * This second step is not optional here, and leaving it out is a silent
	 * data-corruption bug that no unit test can see.
	 *
	 * Since WordPress 4.8.3, `prepare()` rewrites every `%` in the string it
	 * returns into a 64-character random hash, so that passing that string
	 * through `prepare()` a second time cannot reinterpret the sign as a new
	 * placeholder.  `wpdb::query()` reverses it on the way to the server, which
	 * is why nobody normally sees it.
	 *
	 * A dump does not go to the server.  It goes to a file.  So every value
	 * containing a literal `%` — a URL with `%20`, a CSS width, an encoded
	 * serialized string, and `wp_options` is full of all three — would be
	 * written into `database.sql` as a hash, import without complaint, and be
	 * wrong.  Reversing the escape here is what makes the file mean what it
	 * says.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		return $this->db->remove_placeholder_escape( (string) $this->db->prepare( $query, ...$args ) );
	}

	/**
	 * @return array<int, array<string, string|null>>
	 */
	public function results( string $query ): array {
		/** @var array<int, array<string, string|null>> $rows */
		$rows = (array) $this->db->get_results( $query, ARRAY_A );

		return $rows;
	}

	/**
	 * @return array<int, array<int, string|null>>
	 */
	public function rows( string $query ): array {
		/** @var array<int, array<int, string|null>> $rows */
		$rows = (array) $this->db->get_results( $query, ARRAY_N );

		return $rows;
	}

	public function var( string $query ): ?string {
		$value = $this->db->get_var( $query );

		return null === $value ? null : (string) $value;
	}

	public function timeZone(): string {
		return (string) $this->db->get_var( 'SELECT @@session.time_zone' );
	}

	public function setTimeZone( string $value ): void {
		$this->db->query( $this->db->prepare( 'SET time_zone = %s', $value ) );
	}

	/**
	 * Drop the last result set, and the query log with it.
	 *
	 * With SAVEQUERIES defined, wpdb appends every query — its text, its
	 * timing, and a backtrace — to $wpdb->queries.  A dump issues one SELECT
	 * per five hundred rows, so a large table accumulates thousands of entries
	 * that nothing will ever read.  That alone can exhaust the memory limit,
	 * which presents as "the backup dies on big sites" and gets blamed on the
	 * dump rather than on the debugging flag someone left on.
	 *
	 * The constant is the whole signal, deliberately: wpdb reads
	 * `defined( 'SAVEQUERIES' ) && SAVEQUERIES` at every query and has no
	 * instance property mirroring it, so there is nothing else to consult.
	 */
	public function release(): void {
		$this->db->flush();

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->db->queries = [];
		}
	}
}
