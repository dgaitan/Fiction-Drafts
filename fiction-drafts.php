<?php

/**
 * Plugin Name: Fiction Drafts
 * Plugin URI:  https://github.com/dgaitan/fiction-drafts
 * Description: Creates complete, downloadable copies of a WordPress site — database, files, or both — as resumable background jobs that never time out.
 * Version:     0.1.0
 * Author:      David Gaitan
 * Author URI:  https://profiles.wordpress.org/david-gaitan/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fiction-drafts
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Tested up to: 6.9
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'FICTION_DRAFTS_VERSION', '0.1.0' );
define( 'FICTION_DRAFTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FICTION_DRAFTS_URL', plugin_dir_url( __FILE__ ) );
define( 'FICTION_DRAFTS_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoloader.
if ( file_exists( FICTION_DRAFTS_DIR . 'vendor/autoload.php' ) ) {
	require_once FICTION_DRAFTS_DIR . 'vendor/autoload.php';
}

/*
 * Action Scheduler — bundled, not borrowed.
 *
 * This plugin ships its own copy so background work never depends on
 * WooCommerce (or any other plugin) being installed or active.  Action
 * Scheduler is explicitly designed to be bundled by many plugins at once:
 * each copy registers its version with ActionScheduler_Versions, and on
 * plugins_loaded the highest registered version boots and serves every
 * copy's as_*() calls.
 *
 * It MUST be required during or before plugins_loaded — file scope here —
 * or version negotiation has already happened by the time we ask.
 */
if ( file_exists( FICTION_DRAFTS_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once FICTION_DRAFTS_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Activation / deactivation hooks must be registered at the top level.
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \FictionDrafts\Plugin::class ) ) {
			\FictionDrafts\Plugin::activate();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \FictionDrafts\Plugin::class ) ) {
			\FictionDrafts\Plugin::deactivate();
		}
	}
);

/*
 * Boot after all plugins are loaded so service providers can depend on other
 * plugins, and so Action Scheduler's version negotiation has settled.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( \FictionDrafts\Plugin::class ) ) {
			\FictionDrafts\Plugin::instance()->boot();
		}
	}
);
