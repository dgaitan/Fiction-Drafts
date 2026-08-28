<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Container\Container;
use FictionDrafts\Container\ContainerException;
use FictionDrafts\Container\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

final class ContainerTest extends TestCase {

	public function testItIsPsr11Compliant(): void {
		$this->assertInstanceOf( ContainerInterface::class, new Container() );
	}

	public function testHasReportsWhetherAnIdIsBound(): void {
		$container = new Container();

		$this->assertFalse( $container->has( 'nothing' ) );

		$container->bind( 'something', static fn (): string => 'value' );

		$this->assertTrue( $container->has( 'something' ) );
	}

	public function testGetThrowsNotFoundForAnUnboundId(): void {
		$this->expectException( NotFoundException::class );

		( new Container() )->get( 'missing' );
	}

	public function testBindRunsTheFactoryOnEveryGet(): void {
		$container = new Container();
		$container->bind( 'fresh', static fn (): stdClass => new stdClass() );

		$this->assertNotSame( $container->get( 'fresh' ), $container->get( 'fresh' ) );
	}

	public function testSingletonReturnsTheSameInstanceEveryTime(): void {
		$container = new Container();
		$container->singleton( 'shared', static fn (): stdClass => new stdClass() );

		$this->assertSame( $container->get( 'shared' ), $container->get( 'shared' ) );
	}

	public function testInstanceBindsAnAlreadyConstructedObject(): void {
		$object    = new stdClass();
		$container = new Container();
		$container->instance( 'preset', $object );

		$this->assertSame( $object, $container->get( 'preset' ) );
	}

	public function testTheContainerPassesItselfToFactories(): void {
		$container = new Container();
		$container->instance( 'dependency', 'resolved' );
		$container->bind(
			'consumer',
			static fn ( Container $c ): string => (string) $c->get( 'dependency' )
		);

		$this->assertSame( 'resolved', $container->get( 'consumer' ) );
	}

	public function testAThrowingFactoryBecomesAContainerException(): void {
		$container = new Container();
		$container->bind(
			'broken',
			static function (): string {
				throw new RuntimeException( 'boom' );
			}
		);

		$this->expectException( ContainerException::class );

		$container->get( 'broken' );
	}

	public function testRebindingClearsAPreviouslyResolvedSingleton(): void {
		$container = new Container();
		$container->singleton( 'service', static fn (): string => 'first' );

		$this->assertSame( 'first', $container->get( 'service' ) );

		$container->singleton( 'service', static fn (): string => 'second' );

		$this->assertSame( 'second', $container->get( 'service' ) );
	}
}
