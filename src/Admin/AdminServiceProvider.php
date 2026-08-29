<?php

declare( strict_types=1 );

namespace FictionDrafts\Admin;

use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Container\Container;
use FictionDrafts\Container\ServiceProvider;
use FictionDrafts\Persistence\SettingsRepository;

/**
 * Binds the admin screen and hooks it up.
 *
 * The plugin file path is resolved from this file rather than injected from a
 * global, so the container has no dependency on the bootstrap file having run
 * in a particular way — the same reason every other provider resolves its own
 * collaborators.
 */
final class AdminServiceProvider implements ServiceProvider {

	public function register( Container $container ): void {
		$container->singleton(
			ProfileCatalogue::class,
			static fn (): ProfileCatalogue => new ProfileCatalogue()
		);

		$container->singleton(
			AdminPage::class,
			static function ( Container $c ): AdminPage {
				$pluginFile = dirname( __DIR__, 2 ) . '/fiction-drafts.php';

				return new AdminPage(
					$c->get( StageRegistry::class ),
					$c->get( ProfileCatalogue::class ),
					$c->get( SettingsRepository::class ),
					$pluginFile,
					// Read from the header, not FICTION_DRAFTS_VERSION — the same
					// reason $pluginFile above is computed rather than taken from
					// FICTION_DRAFTS_DIR: the container has no dependency on the
					// bootstrap file having defined anything.
					(string) ( get_file_data( $pluginFile, [ 'Version' => 'Version' ] )['Version'] ?? '' )
				);
			}
		);
	}

	public function boot( Container $container ): void {
		if ( ! is_admin() ) {
			return;
		}

		/** @var AdminPage $page */
		$page = $container->get( AdminPage::class );
		$page->register();
	}
}
