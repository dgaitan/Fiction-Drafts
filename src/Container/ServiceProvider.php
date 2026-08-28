<?php

declare( strict_types=1 );

namespace FictionDrafts\Container;

/**
 * A unit of service registration.
 *
 * register() binds factories and MUST NOT resolve anything.  boot() may
 * resolve services and attach WordPress hooks, and runs only after every
 * provider has registered.
 */
interface ServiceProvider {

	public function register( Container $container ): void;

	public function boot( Container $container ): void;
}
