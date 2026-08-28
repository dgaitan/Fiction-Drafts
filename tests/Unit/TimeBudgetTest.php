<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\TimeBudget;
use PHPUnit\Framework\TestCase;

final class TimeBudgetTest extends TestCase {

	public function testAZeroSecondBudgetIsExhaustedImmediately(): void {
		$this->assertTrue( ( new TimeBudget( 0 ) )->exhausted() );
	}

	public function testAFreshBudgetIsNotExhausted(): void {
		$this->assertFalse( ( new TimeBudget( 30 ) )->exhausted() );
	}

	public function testElapsedGrowsAndRemainingShrinks(): void {
		$budget = new TimeBudget( 5 );

		usleep( 20000 );

		$this->assertGreaterThan( 0.0, $budget->elapsed() );
		$this->assertLessThan( 5.0, $budget->remaining() );
	}

	public function testRemainingNeverGoesNegative(): void {
		$this->assertSame( 0.0, ( new TimeBudget( 0 ) )->remaining() );
	}

	public function testAMemoryCeilingOfZeroExhaustsTheBudgetOnMemoryAlone(): void {
		// Any real usage exceeds a ceiling of 0% of memory_limit, so this
		// isolates the memory arm from the clock arm.
		$budget = new TimeBudget( 3600, 0.0 );

		$this->assertTrue( $budget->memoryExhausted() );
		$this->assertTrue( $budget->exhausted() );
	}

	public function testAMemoryCeilingAboveTheLimitDoesNotTrip(): void {
		$budget = new TimeBudget( 3600, 100.0 );

		$this->assertFalse( $budget->memoryExhausted() );
	}

	public function testFromEnvironmentFallsBackToTheCeilingWhenExecutionTimeIsUnlimited(): void {
		// PHP CLI reports max_execution_time = 0, meaning unlimited.
		if ( 0 !== (int) ini_get( 'max_execution_time' ) ) {
			$this->markTestSkipped( 'This host reports a finite max_execution_time.' );
		}

		$this->assertSame( TimeBudget::DEFAULT_SECONDS, TimeBudget::fromEnvironment()->seconds() );
		$this->assertSame( 7, TimeBudget::fromEnvironment( 7 )->seconds() );
	}
}
