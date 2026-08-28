<?php

/**
 * PHPStan bootstrap.
 *
 * Defines the plugin constants that fiction-drafts.php declares at runtime so
 * static analysis of files that reference them does not report them as
 * undefined.  WordPress function signatures come from
 * szepeviktor/phpstan-wordpress, not from here.
 */

declare( strict_types=1 );

define( 'FICTION_DRAFTS_VERSION', '0.1.0' );
define( 'FICTION_DRAFTS_DIR', __DIR__ . '/../' );
define( 'FICTION_DRAFTS_URL', 'https://example.test/wp-content/plugins/fiction-drafts/' );
define( 'FICTION_DRAFTS_BASENAME', 'fiction-drafts/fiction-drafts.php' );

// PclZip defines these at the foot of wp-admin/includes/class-pclzip.php, which
// static analysis never loads.  Guarded because this file is a bootstrap, not a
// stub: if the real class is ever present, its own values win.
if ( ! defined( 'PCLZIP_OPT_ADD_PATH' ) ) {
	define( 'PCLZIP_OPT_ADD_PATH', 77002 );
	define( 'PCLZIP_OPT_REMOVE_PATH', 77003 );
	define( 'PCLZIP_OPT_REMOVE_ALL_PATH', 77004 );
	define( 'PCLZIP_OPT_BY_INDEX', 77012 );
}
