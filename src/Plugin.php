<?php

declare( strict_types=1 );

namespace FictionDrafts;

use FictionDrafts\Admin\AdminServiceProvider;
use FictionDrafts\Container\Container;
use FictionDrafts\Backup\BackupServiceProvider;
use FictionDrafts\Backup\Scheduler;
use FictionDrafts\Container\ServiceProvider;
use FictionDrafts\Download\DownloadServiceProvider;
use FictionDrafts\Persistence\Migrator;
use FictionDrafts\Persistence\PersistenceServiceProvider;
use FictionDrafts\Rest\RestServiceProvider;
use FictionDrafts\Storage\StorageLocator;

/**
 * The composition root.
 *
 * Every hook and service in Fiction Drafts is registered from here, through a
 * service provider.  Nothing bootstraps itself from a file's global scope.
 */
final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Register every provider, then boot them.
	 *
	 * Registration and booting are two passes on purpose: a provider may only
	 * resolve services from the container during boot(), never during
	 * register(), so registration order never becomes load-bearing.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$providers = $this->providers();

		foreach ( $providers as $provider ) {
			$provider->register( $this->container );
		}

		foreach ( $providers as $provider ) {
			$provider->boot( $this->container );
		}

		$this->booted = true;
	}

	public function isBooted(): bool {
		return $this->booted;
	}

	/**
	 * Create the tables, the storage directory, and the recurring actions.
	 *
	 * Order matters: the schema has to exist before anything can write a job,
	 * and the storage directory has to exist before a job can be started.
	 */
	public static function activate(): void {
		( new Migrator() )->run();
		( new StorageLocator() )->ensure();
		( new Scheduler() )->scheduleRecurring();
	}

	/**
	 * Stop scheduled work, but keep every table, option, and archive.
	 *
	 * Deactivation is not uninstallation.  A site owner who deactivates the
	 * plugin to debug something else must not lose their backups.
	 */
	public static function deactivate(): void {
		( new Scheduler() )->unscheduleAll();
	}

	/**
	 * Service providers, in registration order.
	 *
	 * @return array<int, ServiceProvider>
	 */
	private function providers(): array {
		return [
			new PersistenceServiceProvider(),
			new BackupServiceProvider(),
			new RestServiceProvider(),
			new DownloadServiceProvider(),
			new AdminServiceProvider(),
		];
	}
}
