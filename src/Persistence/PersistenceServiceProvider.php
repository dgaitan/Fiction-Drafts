<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

use FictionDrafts\Container\Container;
use FictionDrafts\Container\ServiceProvider;
use FictionDrafts\Storage\StorageLocator;

/**
 * Binds everything that reads or writes stored state.
 *
 * SettingsRepository and StorageLocator both hold per-request caches, so every
 * consumer must resolve the same instance rather than constructing its own.
 */
final class PersistenceServiceProvider implements ServiceProvider {

	public function register( Container $container ): void {
		$container->singleton(
			SettingsRepository::class,
			static fn (): SettingsRepository => new SettingsRepository()
		);

		$container->singleton(
			Migrator::class,
			static fn (): Migrator => new Migrator()
		);

		$container->singleton(
			StorageLocator::class,
			static fn (): StorageLocator => new StorageLocator()
		);

		// StageRunner depends on the interface, not the implementation, so the
		// resumability proof can run the engine against an in-memory store.
		$container->singleton(
			JobStore::class,
			static fn (): JobStore => new JobRepository()
		);

		$container->singleton(
			VolumeStore::class,
			static fn (): VolumeStore => new VolumeRepository()
		);
	}

	public function boot( Container $container ): void {
		// The retention sweep is hooked by BackupServiceProvider: it needs the
		// scheduler's hook name and the storage locator, and every other
		// consumer of Action Scheduler lives there.
	}
}
