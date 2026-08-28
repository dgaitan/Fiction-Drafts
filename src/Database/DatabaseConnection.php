<?php

declare( strict_types=1 );

namespace FictionDrafts\Database;

/**
 * The narrow slice of $wpdb the dump needs.
 *
 * Sprint 2 kept `wpdb` confined to two files in Persistence so the engine's
 * behaviour could be proved against an in-memory store.  The same argument
 * applies here, and more sharply: the rules this sprint has to get right —
 * NULL versus empty string, binary versus text, quote and newline escaping —
 * are exactly the rules that a test needing a live MySQL server would be
 * written loosely enough to miss.
 *
 * So the four dump classes talk to this interface, `WpdbConnection` is the one
 * implementation that touches the global, and the round-trip against real
 * MySQL 8 verifies the whole assembly rather than standing in for the unit
 * tests.
 */
interface DatabaseConnection {

	/**
	 * This site's table prefix, e.g. `wp_` or `wp_2_` on a subsite.
	 */
	public function prefix(): string;

	/**
	 * The network-wide prefix, e.g. `wp_`.  Equal to prefix() on single site.
	 */
	public function basePrefix(): string;

	public function isMultisite(): bool;

	/**
	 * Escape `%` and `_` so a value can be used inside a LIKE pattern.
	 */
	public function escLike( string $text ): string;

	/**
	 * Interpolate values into a query safely.
	 *
	 * `prepare( '%s', $v )` returns the value escaped AND single-quoted, which
	 * is what RowSerializer relies on.
	 */
	public function prepare( string $query, mixed ...$args ): string;

	/**
	 * Rows as associative arrays.  Every value arrives as a string or null,
	 * which is what the serializer's rules are written against.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public function results( string $query ): array;

	/**
	 * Rows as positional arrays — for `SHOW` statements whose column names
	 * vary by server version.
	 *
	 * @return array<int, array<int, string|null>>
	 */
	public function rows( string $query ): array;

	public function var( string $query ): ?string;

	/**
	 * The session time zone, as MySQL reports it.
	 *
	 * A `timestamp` column is stored in UTC and converted to and from the
	 * session time zone on every read and write.  A dump read in one zone and
	 * imported in another shifts every such value, imports without complaint,
	 * and is wrong — so the dump reads in UTC and says so in its header.
	 */
	public function timeZone(): string;

	public function setTimeZone( string $value ): void;

	/**
	 * Release the last result set.
	 *
	 * Without this, `$wpdb->last_result` holds one batch alive for the whole
	 * of the next batch, which doubles the dump's peak memory for no benefit.
	 */
	public function release(): void;
}
