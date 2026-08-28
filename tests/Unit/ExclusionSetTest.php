<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\ExclusionSet;
use PHPUnit\Framework\TestCase;

final class ExclusionSetTest extends TestCase {

	public function testAnEmptySetExcludesNothing(): void {
		$set = new ExclusionSet();

		$this->assertTrue( $set->isEmpty() );
		$this->assertFalse( $set->matches( 'wp-config.php' ) );
	}

	public function testDoubleStarMatchesEverythingBeneathADirectory(): void {
		$set = new ExclusionSet( [ 'wp-content/uploads/**' ] );

		$this->assertTrue( $set->matches( 'wp-content/uploads/2024/01/photo.jpg' ) );
		$this->assertTrue( $set->matches( 'wp-content/uploads/photo.jpg' ) );
	}

	public function testDoubleStarAlsoMatchesTheBareDirectory(): void {
		$set = new ExclusionSet( [ 'wp-content/cache/**' ] );

		$this->assertTrue( $set->matches( 'wp-content/cache' ) );
	}

	public function testAPatternDoesNotBleedIntoASimilarlyNamedSibling(): void {
		$set = new ExclusionSet( [ 'wp-content/uploads/**' ] );

		$this->assertFalse( $set->matches( 'wp-content/uploads-custom/photo.jpg' ) );
		$this->assertFalse( $set->matches( 'wp-content/uploadsomething' ) );
	}

	public function testSingleStarStaysInsideOnePathSegment(): void {
		$set = new ExclusionSet( [ 'wp-content/*.log' ] );

		$this->assertTrue( $set->matches( 'wp-content/debug.log' ) );
		$this->assertFalse( $set->matches( 'wp-content/plugins/nested.log' ) );
	}

	public function testAnExactPatternMatchesLiterally(): void {
		$set = new ExclusionSet( [ 'wp-config.php' ] );

		$this->assertTrue( $set->matches( 'wp-config.php' ) );
		$this->assertFalse( $set->matches( 'wp-config-sample.php' ) );
	}

	public function testADotInAPatternIsNotAWildcard(): void {
		$set = new ExclusionSet( [ '.git/**' ] );

		$this->assertTrue( $set->matches( '.git/HEAD' ) );
		$this->assertFalse( $set->matches( 'xgit/HEAD' ) );
	}

	public function testLeadingSlashesAndBackslashesAreNormalised(): void {
		$set = new ExclusionSet( [ '/wp-content/uploads/**' ] );

		$this->assertTrue( $set->matches( 'wp-content\\uploads\\2024\\photo.jpg' ) );
		$this->assertTrue( $set->matches( '/wp-content/uploads/photo.jpg' ) );
	}

	public function testWithAddsPatternsAndReturnsANewSet(): void {
		$original = new ExclusionSet( [ 'a/**' ] );
		$extended = $original->with( 'b/**' );

		$this->assertFalse( $original->matches( 'b/file.txt' ) );
		$this->assertTrue( $extended->matches( 'b/file.txt' ) );
	}

	public function testWithoutLiftsASinglePattern(): void {
		// This is exactly how the per-job wp-config.php opt-in is expressed.
		$defaults = new ExclusionSet( [ 'wp-config.php', 'node_modules/**' ] );
		$optedIn  = $defaults->without( 'wp-config.php' );

		$this->assertTrue( $defaults->matches( 'wp-config.php' ) );
		$this->assertFalse( $optedIn->matches( 'wp-config.php' ) );
		$this->assertTrue( $optedIn->matches( 'node_modules/pkg/index.js' ) );
	}

	public function testDuplicatePatternsAreCollapsed(): void {
		$set = new ExclusionSet( [ 'a/**', 'a/**', '' ] );

		$this->assertSame( [ 'a/**' ], $set->patterns() );
	}
}
