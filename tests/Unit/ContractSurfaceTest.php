<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\Stages\ArchiveStage;
use FictionDrafts\Backup\Stages\DatabaseStage;
use FictionDrafts\Backup\Stages\FileScanStage;
use FictionDrafts\Backup\Stages\FinalizeStage;
use FictionDrafts\Backup\Stages\PrepareStage;
use FictionDrafts\Contracts\ArchiveWriter;
use FictionDrafts\Contracts\Destination;
use FictionDrafts\Contracts\FileSource;
use FictionDrafts\Contracts\Stage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Sprint 0's actual deliverable: the contract surface exists and is typed.
 *
 * These assertions are deliberately about shape rather than behaviour — there
 * are no implementations yet.  They exist so that a later sprint cannot
 * quietly widen or drop a contract method without a test turning red.
 */
final class ContractSurfaceTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function contractProvider(): array {
		return [
			'Stage'         => [ Stage::class, [ 'id', 'label', 'appliesTo', 'run' ] ],
			'ArchiveWriter' => [ ArchiveWriter::class, [ 'open', 'addFile', 'addFromString', 'entryCount', 'bytesWritten', 'truncateTo', 'close' ] ],
			'FileSource'    => [ FileSource::class, [ 'iterate' ] ],
			'Destination'   => [ Destination::class, [ 'id', 'label', 'deliver', 'remove' ] ],
		];
	}

	/**
	 * @dataProvider contractProvider
	 *
	 * @param string            $interface Fully-qualified interface name.
	 * @param array<int,string> $methods   Methods the interface must declare.
	 */
	public function testContractIsAnInterfaceDeclaringItsMethods( string $interface, array $methods ): void {
		$this->assertTrue( interface_exists( $interface ), $interface . ' must exist.' );

		$reflection = new ReflectionClass( $interface );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				$interface . ' must declare ' . $method . '().'
			);
		}

		$this->assertCount(
			count( $methods ),
			$reflection->getMethods(),
			$interface . ' declares an unexpected number of methods.'
		);
	}

	/**
	 * @dataProvider contractProvider
	 *
	 * @param string            $interface Fully-qualified interface name.
	 * @param array<int,string> $methods   Methods the interface must declare.
	 */
	public function testEveryContractMethodDeclaresAReturnType( string $interface, array $methods ): void {
		$reflection = new ReflectionClass( $interface );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				$reflection->getMethod( $method )->hasReturnType(),
				$interface . '::' . $method . '() must declare a return type.'
			);
		}
	}

	public function testStageRunTakesJobCursorAndBudgetAndReturnsAResult(): void {
		$run = ( new ReflectionClass( Stage::class ) )->getMethod( 'run' );

		$parameterTypes = array_map(
			static function ( $parameter ): string {
				$type = $parameter->getType();

				return $type instanceof ReflectionNamedType ? $type->getName() : '';
			},
			$run->getParameters()
		);

		$this->assertSame(
			[
				'FictionDrafts\Domain\BackupJob',
				'FictionDrafts\Domain\StageCursor',
				'FictionDrafts\Domain\TimeBudget',
			],
			$parameterTypes
		);

		$returnType = $run->getReturnType();
		$this->assertInstanceOf( ReflectionNamedType::class, $returnType );
		$this->assertSame( 'FictionDrafts\Domain\StageResult', $returnType->getName() );
	}

	/**
	 * The shipped pipeline, and nothing else.
	 *
	 * Through Sprint 2 this asserted that no production class implemented
	 * Stage at all.  Sprints 3 and 4 built three of them, so the assertion
	 * narrows rather than disappearing: exactly these three exist, and
	 * PrepareStage and FinalizeStage still do not.
	 */
	public function testOnlyTheShippedStagesImplementTheContract(): void {
		foreach ( [ DatabaseStage::class, FileScanStage::class, PrepareStage::class, ArchiveStage::class, FinalizeStage::class ] as $stage ) {
			class_exists( $stage );
		}

		$implementations = array_filter(
			get_declared_classes(),
			static fn ( string $class ): bool => str_starts_with( $class, 'FictionDrafts\\' )
				&& ! str_starts_with( $class, 'FictionDrafts\\Tests\\' )
				&& in_array( Stage::class, self::interfacesOf( $class ), true )
		);

		$implementations = array_values( $implementations );

		sort( $implementations, SORT_STRING );

		$this->assertSame(
			[
				ArchiveStage::class,
				DatabaseStage::class,
				FileScanStage::class,
				FinalizeStage::class,
				PrepareStage::class,
			],
			$implementations,
			'a new Stage appeared without being declared here'
		);
	}

	/**
	 * ISC-316 — the pipeline order, read off the composition root.
	 *
	 * StageRegistry preserves the order stages are added to the filter in, so
	 * the order of these three lines *is* the pipeline: the dump has to exist
	 * before the scan counts it, and both before the archive reads them.  A
	 * live job driven through all five is the sprint's integration proof;
	 * this is the cheap check that guards the ordering itself.
	 *
	 * Prepare's position is the one worth stating: it refuses the job when
	 * there is not room for the archive, and the only honest number to refuse
	 * on is the byte total the scan has just finished measuring. Placed first
	 * it would gate on a guess.
	 */
	public function testTheProviderRegistersStagesInPipelineOrder(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Backup/BackupServiceProvider.php' );

		$positions = [];

		foreach ( [ 'DatabaseStage', 'FileScanStage', 'PrepareStage', 'ArchiveStage', 'FinalizeStage' ] as $stage ) {
			$position = strpos( $source, '$stages[] = $container->get( ' . $stage . '::class );' );

			$this->assertIsInt( $position, $stage . ' should be registered on the stages filter' );

			$positions[ $stage ] = $position;
		}

		$this->assertLessThan( $positions['FileScanStage'], $positions['DatabaseStage'] );
		$this->assertLessThan( $positions['PrepareStage'], $positions['FileScanStage'] );
		$this->assertLessThan( $positions['ArchiveStage'], $positions['PrepareStage'] );
		$this->assertLessThan( $positions['FinalizeStage'], $positions['ArchiveStage'] );
	}

	/**
	 * The proof's own stage must really implement the contract, or the proof
	 * demonstrates nothing about the contract.
	 */
	public function testTheProofStageImplementsTheContract(): void {
		$this->assertContains(
			Stage::class,
			self::interfacesOf( \FictionDrafts\Tests\Support\CountingStage::class )
		);
	}

	/**
	 * @param  string $class Fully-qualified class name.
	 * @return array<int, string>
	 */
	private static function interfacesOf( string $class ): array {
		$interfaces = class_implements( $class );

		return false === $interfaces ? [] : array_values( $interfaces );
	}
}
