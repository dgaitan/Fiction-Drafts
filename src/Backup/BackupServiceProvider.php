<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Archive\ArchiveWriterFactory;
use FictionDrafts\Backup\Stages\ArchiveStage;
use FictionDrafts\Backup\Stages\DatabaseStage;
use FictionDrafts\Backup\Stages\FileScanStage;
use FictionDrafts\Backup\Stages\FinalizeStage;
use FictionDrafts\Backup\Stages\PrepareStage;
use FictionDrafts\Container\Container;
use FictionDrafts\Container\ServiceProvider;
use FictionDrafts\Database\DatabaseConnection;
use FictionDrafts\Database\WpdbConnection;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\MySqlJobLock;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Storage\StorageLocator;

/**
 * Binds the engine and attaches it to Action Scheduler.
 *
 * The `fiction_drafts/run_step` listener is the only entry point into
 * StageRunner in production; everything else — REST, and the CLI command
 * planned for v0.2.0 — enqueues a step rather than running one inline.
 */
final class BackupServiceProvider implements ServiceProvider {

	public function register( Container $container ): void {
		$container->singleton(
			JobLock::class,
			static fn (): JobLock => new MySqlJobLock()
		);

		$container->singleton(
			Scheduler::class,
			static fn (): Scheduler => new Scheduler()
		);

		$container->singleton(
			StageRegistry::class,
			static fn (): StageRegistry => new StageRegistry()
		);

		$container->singleton(
			DatabaseConnection::class,
			static fn (): DatabaseConnection => WpdbConnection::global()
		);

		$container->singleton(
			DatabaseStage::class,
			static fn ( Container $c ): DatabaseStage => new DatabaseStage(
				$c->get( DatabaseConnection::class ),
				$c->get( StorageLocator::class )
			)
		);

		$container->singleton(
			ArchiveWriterFactory::class,
			static fn (): ArchiveWriterFactory => new ArchiveWriterFactory()
		);

		$container->singleton(
			FileScanStage::class,
			static fn ( Container $c ): FileScanStage => new FileScanStage(
				$c->get( StorageLocator::class ),
				$c->get( SettingsRepository::class )
			)
		);

		$container->singleton(
			ArchiveStage::class,
			static fn ( Container $c ): ArchiveStage => new ArchiveStage(
				$c->get( StorageLocator::class ),
				$c->get( ArchiveWriterFactory::class ),
				$c->get( SettingsRepository::class )
			)
		);

		$container->singleton(
			Manifest::class,
			static fn ( Container $c ): Manifest => new Manifest(
				$c->get( DatabaseConnection::class )
			)
		);

		$container->singleton(
			Preflight::class,
			static fn ( Container $c ): Preflight => new Preflight(
				$c->get( StorageLocator::class )
			)
		);

		$container->singleton(
			PrepareStage::class,
			static fn ( Container $c ): PrepareStage => new PrepareStage(
				$c->get( StorageLocator::class ),
				$c->get( Preflight::class ),
				$c->get( Manifest::class )
			)
		);

		$container->singleton(
			FinalizeStage::class,
			static fn ( Container $c ): FinalizeStage => new FinalizeStage(
				$c->get( StorageLocator::class ),
				$c->get( VolumeStore::class ),
				$c->get( JobStore::class )
			)
		);

		$container->singleton(
			BackupRemover::class,
			static fn ( Container $c ): BackupRemover => new BackupRemover(
				$c->get( JobStore::class ),
				$c->get( VolumeStore::class ),
				$c->get( StorageLocator::class )
			)
		);

		$container->singleton(
			RetentionSweeper::class,
			static fn ( Container $c ): RetentionSweeper => new RetentionSweeper(
				$c->get( JobStore::class ),
				$c->get( BackupRemover::class ),
				$c->get( SettingsRepository::class )
			)
		);

		$container->singleton(
			StageRunner::class,
			static fn ( Container $c ): StageRunner => new StageRunner(
				$c->get( JobStore::class ),
				$c->get( StageRegistry::class ),
				$c->get( Scheduler::class ),
				// A different named lock from the one JobManager takes: that
				// one guards creating a job, this one guards running a step of
				// it.  Sharing a name would make starting a backup block on a
				// step of the backup already running.
				new MySqlJobLock( 'run_step' )
			)
		);

		$container->singleton(
			JobManager::class,
			static fn ( Container $c ): JobManager => new JobManager(
				$c->get( JobStore::class ),
				$c->get( Scheduler::class ),
				$c->get( StorageLocator::class ),
				$c->get( JobLock::class )
			)
		);

		$container->singleton(
			FailureHandler::class,
			static fn ( Container $c ): FailureHandler => new FailureHandler(
				$c->get( JobStore::class ),
				$c->get( StageRunner::class )
			)
		);

		$container->singleton(
			StaleJobWatchdog::class,
			static fn ( Container $c ): StaleJobWatchdog => new StaleJobWatchdog(
				$c->get( JobStore::class ),
				$c->get( StageRunner::class )
			)
		);
	}

	public function boot( Container $container ): void {
		/** @var StageRunner $runner */
		$runner = $container->get( StageRunner::class );

		add_action(
			Scheduler::HOOK_RUN_STEP,
			static function ( string $uuid ) use ( $runner ): void {
				$runner->step( $uuid );
			}
		);

		/** @var FailureHandler $failures */
		$failures = $container->get( FailureHandler::class );
		$failures->register();

		/** @var StaleJobWatchdog $watchdog */
		$watchdog = $container->get( StaleJobWatchdog::class );
		$watchdog->register();

		/** @var RetentionSweeper $retention */
		$retention = $container->get( RetentionSweeper::class );
		$retention->register();

		// The pipeline is assembled through the public filter rather than a
		// hard-coded array, so this plugin's own stages register the same way a
		// third party's would.  Order here is pipeline order: the dump exists
		// before the scan counts it, and both exist before the archive reads
		// them.
		//
		// Prepare sits third rather than first on purpose: its job is to
		// refuse the archive when there is not room for it, and the only
		// honest number to refuse on is the one the scan just finished
		// measuring.  Nothing large is written before it.
		add_filter(
			StageRegistry::FILTER,
			static function ( array $stages ) use ( $container ): array {
				$stages[] = $container->get( DatabaseStage::class );
				$stages[] = $container->get( FileScanStage::class );
				$stages[] = $container->get( PrepareStage::class );
				$stages[] = $container->get( ArchiveStage::class );
				$stages[] = $container->get( FinalizeStage::class );

				return $stages;
			}
		);
	}
}
