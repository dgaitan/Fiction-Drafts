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
			static fn ( Container $c ): AdminPage => new AdminPage(
				$c->get( StageRegistry::class ),
				$c->get( ProfileCatalogue::class ),
				$c->get( SettingsRepository::class ),
				dirname( __DIR__, 2 ) . '/fiction-drafts.php'
			)
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
