<?php

declare( strict_types=1 );

namespace FictionDrafts\Container;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * A small PSR-11 container.
 *
 * Deliberately not an auto-wiring container: every service is bound with an
 * explicit factory, so a service's dependencies are always readable in one
 * place rather than inferred from constructor reflection.
 */
final class Container implements ContainerInterface {

	/**
	 * @var array<string, callable(Container): mixed>
	 */
	private array $factories = [];

	/**
	 * @var array<string, bool>
	 */
	private array $shared = [];

	/**
	 * @var array<string, mixed>
	 */
	private array $resolved = [];

	/**
	 * Bind a factory that runs on every get().
	 *
	 * @param callable(Container): mixed $factory Builds the service.
	 */
	public function bind( string $id, callable $factory ): void {
		unset( $this->resolved[ $id ] );

		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = false;
	}

	/**
	 * Bind a factory that runs once; every later get() returns the same instance.
	 *
	 * @param callable(Container): mixed $factory Builds the service.
	 */
	public function singleton( string $id, callable $factory ): void {
		unset( $this->resolved[ $id ] );

		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = true;
	}

	/**
	 * Bind an already-constructed object.
	 */
	public function instance( string $id, mixed $service ): void {
		$this->factories[ $id ] = static fn (): mixed => $service;
		$this->shared[ $id ]    = true;
		$this->resolved[ $id ]  = $service;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * @throws NotFoundException  When nothing is bound under $id.
	 * @throws ContainerException When the bound factory throws.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new NotFoundException(
				sprintf( 'Fiction Drafts: no service is bound under "%s".', $id )
			);
		}

		try {
			$service = ( $this->factories[ $id ] )( $this );
		} catch ( Throwable $e ) {
			throw new ContainerException(
				sprintf( 'Fiction Drafts: failed to resolve "%s": %s', $id, $e->getMessage() ),
				0,
				$e
			);
		}

		if ( true === $this->shared[ $id ] ) {
			$this->resolved[ $id ] = $service;
		}

		return $service;
	}
}
