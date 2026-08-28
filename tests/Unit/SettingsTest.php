<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\ExclusionSet;
use FictionDrafts\Domain\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function testDefaultVolumeSizeIsOneAndAHalfGibibytes(): void {
		$this->assertSame( 1610612736, Settings::defaults()->maxVolumeBytes() );
	}

	public function testDefaultRetentionCountIsFive(): void {
		$this->assertSame( 5, Settings::defaults()->retentionCount() );
	}

	public function testDefaultProfileIsFull(): void {
		$this->assertSame( BackupProfile::Full, Settings::defaults()->defaultProfile() );
	}

	public function testDefaultsCarryNoUserExclusions(): void {
		$this->assertTrue( Settings::defaults()->exclusions()->isEmpty() );
	}

	public function testRoundTripsThroughAnArrayWithoutLoss(): void {
		$settings = Settings::create(
			BackupProfile::FilesNoMedia,
			new ExclusionSet( [ 'wp-content/uploads/huge/**', '*.sql' ] ),
			52428800,
			2
		);

		$this->assertEquals( $settings, Settings::fromArray( $settings->toArray() ) );
	}

	public function testAnEmptyArrayHydratesToDefaults(): void {
		$this->assertEquals( Settings::defaults(), Settings::fromArray( [] ) );
	}

	public function testAnUnrecognisedProfileFallsBackToFull(): void {
		$settings = Settings::fromArray( [ 'default_profile' => 'nonsense' ] );

		$this->assertSame( BackupProfile::Full, $settings->defaultProfile() );
	}

	public function testANonStringProfileFallsBackToFull(): void {
		$settings = Settings::fromArray( [ 'default_profile' => 42 ] );

		$this->assertSame( BackupProfile::Full, $settings->defaultProfile() );
	}

	public function testAVolumeSizeBelowTheFloorIsClampedUp(): void {
		$settings = Settings::fromArray( [ 'max_volume_bytes' => 1024 ] );

		$this->assertSame( Settings::MIN_MAX_VOLUME_BYTES, $settings->maxVolumeBytes() );
	}

	public function testANegativeRetentionCountIsClampedToZero(): void {
		$settings = Settings::fromArray( [ 'retention_count' => -3 ] );

		$this->assertSame( 0, $settings->retentionCount() );
	}

	public function testNonStringExclusionPatternsAreDiscarded(): void {
		$settings = Settings::fromArray(
			[
				'exclusions' => [ 'valid/**', 17, null, 'also-valid.php' ],
			]
		);

		$this->assertSame( [ 'valid/**', 'also-valid.php' ], $settings->exclusions()->patterns() );
	}

	public function testANumericStringVolumeSizeIsCoerced(): void {
		$settings = Settings::fromArray( [ 'max_volume_bytes' => '104857600' ] );

		$this->assertSame( 104857600, $settings->maxVolumeBytes() );
	}

	public function testToArrayUsesTheStoredProfileSlug(): void {
		$settings = Settings::defaults()->withDefaultProfile( BackupProfile::DatabaseOnly );

		$this->assertSame( 'database_only', $settings->toArray()['default_profile'] );
	}

	public function testWithersReturnNewInstancesAndLeaveTheOriginalAlone(): void {
		$original = Settings::defaults();
		$changed  = $original->withRetentionCount( 9 );

		$this->assertSame( 9, $changed->retentionCount() );
		$this->assertSame( 5, $original->retentionCount() );
		$this->assertNotSame( $original, $changed );
	}

	public function testWithMaxVolumeBytesAlsoClamps(): void {
		$this->assertSame(
			Settings::MIN_MAX_VOLUME_BYTES,
			Settings::defaults()->withMaxVolumeBytes( 1 )->maxVolumeBytes()
		);
	}

	public function testWithExclusionsReplacesTheUserPatterns(): void {
		$settings = Settings::defaults()->withExclusions( new ExclusionSet( [ 'tmp/**' ] ) );

		$this->assertSame( [ 'tmp/**' ], $settings->exclusions()->patterns() );
	}
}
