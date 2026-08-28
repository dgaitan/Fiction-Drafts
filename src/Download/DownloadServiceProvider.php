<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

use FictionDrafts\Container\Container;
use FictionDrafts\Container\ServiceProvider;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\MySqlJobLock;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Storage\StorageLocator;

/**
 * Binds the download path and hooks it to `admin-post.php`.
 *
 * Separate from the REST provider because the download is not a REST route and
 * saying so in the wiring is cheaper than saying so in a comment: the route
 * that *issues* a grant lives in `RestServiceProvider`, and the handler that
 * spends one lives here.
 */
final class DownloadServiceProvider implements ServiceProvider {

	public function register( Container $container ): void {
		$container->singleton(
			GrantStore::class,
			// Its own named lock. Sharing `run_step` would make claiming a
			// download token block behind a backup that is mid-archive, and
			// sharing `job_create` would make it block behind a starting one.
			//
			// Three seconds rather than the zero the job locks use. This lock
			// guards one option read and one option write, and the requests
			// contending for it are all legitimate: an administrator fetching
			// two volumes of the same backup contends with themselves. At zero
			// the loser is refused outright and reads a message saying the link
			// was already used, which is both wrong and alarming. Three seconds
			// is far longer than the section can take and far shorter than a
			// user notices.
			static fn (): GrantStore => new OptionGrantStore( new MySqlJobLock( 'download_grant', 3 ) )
		);

		$container->singleton(
			ResponseEmitter::class,
			static fn (): ResponseEmitter => new PhpResponseEmitter()
		);

		$container->singleton(
			DownloadHandler::class,
			static fn ( Container $c ): DownloadHandler => new DownloadHandler(
				$c->get( JobStore::class ),
				$c->get( VolumeStore::class ),
				$c->get( StorageLocator::class ),
				$c->get( GrantStore::class ),
				$c->get( ResponseEmitter::class )
			)
		);
	}

	public function boot( Container $container ): void {
		// `admin_post_` fires on admin-post.php only, which is loaded for both
		// admin and front-end requests, so there is no is_admin() gate here —
		// one would stop the download working from a link opened in a new tab.
		/** @var DownloadHandler $handler */
		$handler = $container->get( DownloadHandler::class );
		$handler->register();
	}
}
