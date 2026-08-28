<?php

declare( strict_types=1 );

namespace FictionDrafts\Database;

/**
 * Which tables belong to this site.
 *
 * The result is an **allow-list**, not a suggestion: `SqlDumper` refuses any
 * name it was not given here.  That is the whole defence against a table name
 * reaching a SQL string from anywhere but this class, and it is why the name
 * never has to be escaped downstream — it has already been proved to be one
 * of a fixed set the server itself reported.
 *
 * Two things a naive prefix match gets wrong:
 *
 * - **Views.** `SHOW TABLES LIKE` names views too, and `SHOW CREATE TABLE` on
 *   a view returns a different result shape, so a site with one view produces
 *   a dump with one malformed block.  `SHOW FULL TABLES` carries the type.
 * - **Multisite.** `wp_` is a prefix of `wp_2_posts`.  A main-site backup that
 *   matches on prefix alone silently sweeps in every subsite on the network.
 */
final class TableEnumerator {

	public const FILTER_EXCLUDED = 'fiction_drafts/excluded_tables';

	public function __construct( private readonly DatabaseConnection $db ) {}

	/**
	 * Every base table belonging to this site, sorted, minus the excluded ones.
	 *
	 * The sort is not cosmetic.  `DatabaseStage` addresses tables by index in
	 * this list across separate HTTP requests, so two runs that disagreed about
	 * the order would resume into the wrong table.
	 *
	 * @return array<int, string>
	 */
	public function forSite(): array {
		$prefix  = $this->db->prefix();
		$pattern = $this->db->escLike( $prefix ) . '%';

		$rows = $this->db->rows(
			$this->db->prepare( 'SHOW FULL TABLES LIKE %s', $pattern )
		);

		$tables = [];

		foreach ( $rows as $row ) {
			$name = (string) ( $row[0] ?? '' );
			$type = (string) ( $row[1] ?? '' );

			if ( '' === $name || 'BASE TABLE' !== $type ) {
				continue;
			}

			if ( ! str_starts_with( $name, $prefix ) ) {
				continue;
			}

			if ( $this->belongsToAnotherSite( $name, $prefix ) ) {
				continue;
			}

			$tables[] = $name;
		}

		sort( $tables, SORT_STRING );

		return array_values( array_diff( $tables, $this->excluded() ) );
	}

	/**
	 * Is this table another site's, on a network whose prefix ours prefixes?
	 *
	 * Only the main site has the problem: its prefix (`wp_`) is a prefix of
	 * every subsite's (`wp_2_`).  A subsite's own prefix is not a prefix of any
	 * other, so the LIKE has already isolated it.
	 */
	private function belongsToAnotherSite( string $name, string $prefix ): bool {
		if ( ! $this->db->isMultisite() || $prefix !== $this->db->basePrefix() ) {
			return false;
		}

		return 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '\d+_/', $name );
	}

	/**
	 * Table names an integrator has asked to keep out of every dump.
	 *
	 * @return array<int, string>
	 */
	private function excluded(): array {
		/** @var array<int, mixed> $excluded */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook name is self::FILTER_EXCLUDED, 'fiction_drafts/excluded_tables'; the sniff cannot resolve a constant.
		$excluded = apply_filters( self::FILTER_EXCLUDED, [], $this->db->prefix() );

		return array_values( array_filter( $excluded, 'is_string' ) );
	}
}
