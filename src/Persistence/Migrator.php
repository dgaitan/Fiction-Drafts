<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

/**
 * Creates and upgrades the plugin's own tables.
 *
 * Two tables, both prefixed `fdrafts_`.  The per-file list deliberately is not
 * one of them: it lives in the job's working directory as `files.jsonl`,
 * because a 100k-file site would otherwise add 100k rows per backup.
 */
final class Migrator {

	public const DB_VERSION = '1';

	public const OPTION_VERSION = 'fiction_drafts_db_version';

	public static function jobsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'fdrafts_jobs';
	}

	public static function volumesTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'fdrafts_volumes';
	}

	/**
	 * Table names this plugin owns, for uninstall to drop.
	 *
	 * @return array<int, string>
	 */
	public static function ownedTables(): array {
		return [ self::jobsTable(), self::volumesTable() ];
	}

	public function currentVersion(): string {
		$stored = get_option( self::OPTION_VERSION, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Bring the schema up to date.
	 *
	 * dbDelta() compares the declared schema against what exists and issues
	 * only the differences, so calling this repeatedly is a no-op.
	 */
	public function run(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		dbDelta( $this->jobsSchema( $charset ) );
		dbDelta( $this->volumesSchema( $charset ) );

		if ( '' === $this->currentVersion() ) {
			add_option( self::OPTION_VERSION, self::DB_VERSION, '', false );

			return;
		}

		update_option( self::OPTION_VERSION, self::DB_VERSION, false );
	}

	public function tablesExist(): bool {
		global $wpdb;

		foreach ( self::ownedTables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema inspection has no cache layer.

			if ( $table !== $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * dbDelta is whitespace- and keyword-sensitive: two spaces after PRIMARY
	 * KEY, one field per line, and index names it can match on a re-run.
	 */
	private function jobsSchema( string $charset ): string {
		$table = self::jobsTable();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			profile varchar(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			stage varchar(32) DEFAULT NULL,
			stage_cursor longtext DEFAULT NULL,
			processed bigint(20) unsigned NOT NULL DEFAULT 0,
			total bigint(20) unsigned NOT NULL DEFAULT 0,
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			options longtext DEFAULT NULL,
			error text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";
	}

	private function volumesSchema( string $charset ): string {
		$table = self::volumesTable();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			sequence smallint(5) unsigned NOT NULL DEFAULT 1,
			filename varchar(191) NOT NULL,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			sha256 char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY job_id (job_id)
		) {$charset};";
	}
}
