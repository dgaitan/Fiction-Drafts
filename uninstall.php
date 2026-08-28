<?php

/**
 * Removes everything Fiction Drafts created — and nothing it did not.
 *
 * That second half is the one that matters. Action Scheduler is a shared
 * library: WooCommerce and any number of other plugins may be using the very
 * same `actionscheduler_*` tables. Dropping them here would break every one of
 * them, silently, at the moment this plugin is removed. So this file drops
 * only tables prefixed `fdrafts_`, deletes only options prefixed
 * `fiction_drafts_`, and unschedules only the `fiction-drafts` action group.
 *
 * @package FictionDrafts
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * Unschedule first, while Action Scheduler is still loaded. The dedicated
 * group is what makes this precise: an empty hook with our group removes
 * exactly our actions.
 */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'fiction-drafts' );
}

/*
 * Remove the storage directory before forgetting the slug that names it —
 * afterwards there is no way left to find it.
 */
$fiction_drafts_slug = get_option( 'fiction_drafts_storage_slug', '' );

$fiction_drafts_base = defined( 'FICTION_DRAFTS_STORAGE_DIR' )
	? untrailingslashit( (string) constant( 'FICTION_DRAFTS_STORAGE_DIR' ) )
	: untrailingslashit( WP_CONTENT_DIR ) . '/fiction-drafts-' . (string) $fiction_drafts_slug;

/**
 * Delete a directory tree without following symlinks.
 *
 * @param string $path Absolute path to remove.
 */
function fiction_drafts_uninstall_rmdir( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		wp_delete_file( $path );

		return;
	}

	if ( ! is_dir( $path ) ) {
		return;
	}

	$entries = scandir( $path );

	if ( false === $entries ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		fiction_drafts_uninstall_rmdir( $path . '/' . $entry );
	}

	rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem is not initialised during uninstall.
}

if ( is_string( $fiction_drafts_slug ) && '' !== $fiction_drafts_slug && is_dir( $fiction_drafts_base ) ) {
	fiction_drafts_uninstall_rmdir( $fiction_drafts_base );
} elseif ( defined( 'FICTION_DRAFTS_STORAGE_DIR' ) && is_dir( $fiction_drafts_base ) ) {
	fiction_drafts_uninstall_rmdir( $fiction_drafts_base );
}

/*
 * Drop this plugin's own tables. Named explicitly rather than matched by a
 * pattern, so no query here can ever reach a table we do not own.
 */
foreach ( [ 'fdrafts_jobs', 'fdrafts_volumes' ] as $fiction_drafts_table ) {
	$fiction_drafts_qualified = $wpdb->prefix . $fiction_drafts_table;

	$wpdb->query( "DROP TABLE IF EXISTS `{$fiction_drafts_qualified}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier built from $wpdb->prefix and a literal; DDL cannot be prepared or cached.
}

/*
 * Delete this plugin's options. Named explicitly for the same reason.
 */
foreach ( [ 'fiction_drafts_settings', 'fiction_drafts_storage_slug', 'fiction_drafts_db_version', 'fiction_drafts_download_grants' ] as $fiction_drafts_option ) {
	delete_option( $fiction_drafts_option );
}

/*
 * Deliberately NOT dropped: actionscheduler_actions, actionscheduler_claims,
 * actionscheduler_groups, actionscheduler_logs. Those belong to the shared
 * library, not to this plugin.
 */
