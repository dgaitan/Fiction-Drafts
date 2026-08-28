<?php

declare( strict_types=1 );

namespace FictionDrafts\Rest;

use FictionDrafts\Admin\ProfileCatalogue;
use FictionDrafts\Backup\BackupRemover;
use FictionDrafts\Backup\JobManager;
use FictionDrafts\Persistence\MySqlJobLock;
use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Container\Container;
use FictionDrafts\Container\ServiceProvider;
use FictionDrafts\Download\GrantStore;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Storage\StorageLocator;

/**
 * Registers the REST surface on rest_api_init.
 *
 * Controllers are listed once, in a constant, and both bound and booted from
 * that list.  A controller added to the container but forgotten in boot() would
 * be a route that exists in the code and not on the server — a failure with no
 * error message anywhere.
 */
final class RestServiceProvider implements ServiceProvider {

	/**
	 * Every controller this plugin registers routes from.
	 *
	 * @var array<int, class-string<AbstractController>>
	 */
	private const CONTROLLERS = [
		JobsController::class,
		BackupsController::class,
		DownloadController::class,
		SettingsController::class,
	];

	public function register( Container $container ): void {
		$container->singleton(
			JobsController::class,
			static fn ( Container $c ): JobsController => new JobsController(
				$c->get( JobManager::class ),
				$c->get( StageRegistry::class )
			)
		);

		$container->singleton(
			BackupsController::class,
			static fn ( Container $c ): BackupsController => new BackupsController(
				$c->get( JobStore::class ),
				$c->get( VolumeStore::class ),
				$c->get( StorageLocator::class ),
				$c->get( BackupRemover::class ),
				$c->get( ProfileCatalogue::class ),
				// The name matters: 'run_step' is the lock StageRunner takes,
				// so a delete and a step exclude each other. A lock of its own
				// would exclude nothing that matters.
				new MySqlJobLock( 'run_step' )
			)
		);

		$container->singleton(
			DownloadController::class,
			static fn ( Container $c ): DownloadController => new DownloadController(
				$c->get( JobStore::class ),
				$c->get( VolumeStore::class ),
				$c->get( StorageLocator::class ),
				$c->get( GrantStore::class )
			)
		);

		$container->singleton(
			SettingsController::class,
			static fn ( Container $c ): SettingsController => new SettingsController(
				$c->get( SettingsRepository::class ),
				$c->get( ProfileCatalogue::class )
			)
		);
	}

	public function boot( Container $container ): void {
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				foreach ( self::CONTROLLERS as $controller ) {
					/** @var AbstractController $instance */
					$instance = $container->get( $controller );
					$instance->registerRoutes();
				}
			}
		);
	}
}
