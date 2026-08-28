<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\ExclusionSet;
use FictionDrafts\Domain\Settings;
use FictionDrafts\Persistence\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercised against the in-memory options table in tests/stubs.
 *
 * The assertions that matter here are not only "the value comes back" but
 * "the row was written with autoload off" — the second is the whole reason
 * this repository exists rather than two bare calls to update_option().
 */
final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fiction_drafts_test_reset_options();
	}

	public function testTheOptionNameIsStable(): void {
		$this->assertSame( 'fiction_drafts_settings', SettingsRepository::OPTION_NAME );
	}

	public function testReturnsDefaultsWhenNothingIsStored(): void {
		$repository = new SettingsRepository();

		$this->assertEquals( Settings::defaults(), $repository->get() );
	}

	public function testCreatesTheRowWithAutoloadOff(): void {
		$repository = new SettingsRepository();

		$this->assertTrue( $repository->save( Settings::defaults() ) );

		$calls = fiction_drafts_test_option_calls();

		$this->assertCount( 1, $calls['add'], 'an absent option must be created with add_option()' );
		$this->assertCount( 0, $calls['update'] );
		$this->assertSame( SettingsRepository::OPTION_NAME, $calls['add'][0]['option'] );
		$this->assertFalse( $calls['add'][0]['autoload'] );
	}

	public function testUpdatesAnExistingRowWithAutoloadOff(): void {
		$repository = new SettingsRepository();
		$repository->save( Settings::defaults() );
		$repository->flush();

		$repository->save( Settings::defaults()->withRetentionCount( 9 ) );

		$calls = fiction_drafts_test_option_calls();

		$this->assertCount( 1, $calls['update'], 'an existing option must go through update_option()' );
		$this->assertSame( SettingsRepository::OPTION_NAME, $calls['update'][0]['option'] );
		$this->assertFalse( $calls['update'][0]['autoload'] );
	}

	public function testNeitherWritePathEverRequestsAutoload(): void {
		$repository = new SettingsRepository();
		$repository->save( Settings::defaults() );
		$repository->flush();
		$repository->save( Settings::defaults()->withRetentionCount( 1 ) );

		$calls = fiction_drafts_test_option_calls();

		foreach ( array_merge( $calls['add'], $calls['update'] ) as $call ) {
			$this->assertFalse( $call['autoload'], 'no write may autoload the settings option' );
		}
	}

	public function testWhatIsSavedIsWhatComesBack(): void {
		$settings = Settings::create(
			BackupProfile::FilesNoMedia,
			new ExclusionSet( [ 'wp-content/uploads/huge/**' ] ),
			104857600,
			2
		);

		$repository = new SettingsRepository();
		$repository->save( $settings );
		$repository->flush();

		$this->assertEquals( $settings, $repository->get() );
	}

	public function testTheStoredPayloadIsThePlainSettingsArray(): void {
		$settings   = Settings::defaults()->withRetentionCount( 4 );
		$repository = new SettingsRepository();
		$repository->save( $settings );

		$calls = fiction_drafts_test_option_calls();

		$this->assertSame( $settings->toArray(), $calls['add'][0]['value'] );
	}

	public function testTheOptionIsReadOnlyOncePerRequest(): void {
		$repository = new SettingsRepository();

		$repository->get();
		$readsAfterFirst = fiction_drafts_test_option_calls()['get'];

		$repository->get();
		$repository->get();

		$this->assertSame( $readsAfterFirst, fiction_drafts_test_option_calls()['get'] );
	}

	public function testSavingPrimesTheCache(): void {
		$settings   = Settings::defaults()->withRetentionCount( 7 );
		$repository = new SettingsRepository();
		$repository->save( $settings );

		$readsAfterSave = fiction_drafts_test_option_calls()['get'];

		$this->assertEquals( $settings, $repository->get() );
		$this->assertSame( $readsAfterSave, fiction_drafts_test_option_calls()['get'] );
	}

	public function testFlushForcesTheNextReadToHitTheOption(): void {
		$repository = new SettingsRepository();
		$repository->get();

		$readsBefore = fiction_drafts_test_option_calls()['get'];
		$repository->flush();
		$repository->get();

		$this->assertGreaterThan( $readsBefore, fiction_drafts_test_option_calls()['get'] );
	}

	public function testACorruptedRowFallsBackToDefaultsRatherThanFataling(): void {
		$GLOBALS['fiction_drafts_test_options'][ SettingsRepository::OPTION_NAME ] = 'not an array';

		$repository = new SettingsRepository();

		$this->assertEquals( Settings::defaults(), $repository->get() );
	}

	public function testAPartialRowIsCompletedFromDefaults(): void {
		$GLOBALS['fiction_drafts_test_options'][ SettingsRepository::OPTION_NAME ] = [
			'retention_count' => 2,
		];

		$settings = ( new SettingsRepository() )->get();

		$this->assertSame( 2, $settings->retentionCount() );
		$this->assertSame( Settings::DEFAULT_MAX_VOLUME_BYTES, $settings->maxVolumeBytes() );
		$this->assertSame( BackupProfile::Full, $settings->defaultProfile() );
	}
}
