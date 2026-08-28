<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Admin\ProfileCatalogue;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\Settings;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Rest\SettingsController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The settings route, one decision at a time.
 */
final class SettingsControllerTest extends TestCase {

	private SettingsRepository $settings;

	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_rest();

		$this->settings   = new SettingsRepository();
		$this->controller = new SettingsController( $this->settings, new ProfileCatalogue() );
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function put( array $params ): WP_REST_Response|WP_Error {
		return $this->controller->update( new WP_REST_Request( $params, 'PUT' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get(): array {
		$response = $this->controller->show( new WP_REST_Request() );

		/** @var array<string, mixed> $data */
		$data = $response->get_data();

		return $data;
	}

	public function testGetReturnsTheFourStoredFields(): void {
		$data = $this->get();

		$this->assertSame( BackupProfile::Full->value, $data['default_profile'] );
		$this->assertSame( [], $data['exclusions'] );
		$this->assertSame( Settings::DEFAULT_MAX_VOLUME_BYTES, $data['max_volume_bytes'] );
		$this->assertSame( Settings::DEFAULT_RETENTION_COUNT, $data['retention_count'] );
	}

	public function testPutPersistsAndASubsequentGetReturnsIt(): void {
		$this->put(
			[
				'default_profile' => BackupProfile::FilesNoMedia->value,
				'retention_count' => 12,
			]
		);

		// A fresh repository, so the assertion reads the option row rather than
		// the cache the write left behind.
		$this->settings   = new SettingsRepository();
		$this->controller = new SettingsController( $this->settings, new ProfileCatalogue() );

		$data = $this->get();

		$this->assertSame( BackupProfile::FilesNoMedia->value, $data['default_profile'] );
		$this->assertSame( 12, $data['retention_count'] );
	}

	public function testAnUnknownProfileIsRefusedRatherThanFallingBackToFull(): void {
		$result = $this->put( [ 'default_profile' => 'everything_probably' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_status() );

		// And nothing was written: a refused request must not half-apply.
		$this->assertSame( BackupProfile::Full->value, $this->get()['default_profile'] );
	}

	public function testAVolumeSizeBelowTheFloorIsClampedAndTheResponseSaysSo(): void {
		$result = $this->put( [ 'max_volume_bytes' => 1024 ] );

		$this->assertInstanceOf( WP_REST_Response::class, $result );

		/** @var array<string, mixed> $data */
		$data = $result->get_data();

		$this->assertSame(
			Settings::MIN_MAX_VOLUME_BYTES,
			$data['max_volume_bytes'],
			'the response must report what was stored, not what was sent'
		);
	}

	public function testANegativeRetentionCountIsClampedToZero(): void {
		$result = $this->put( [ 'retention_count' => -4 ] );

		$this->assertInstanceOf( WP_REST_Response::class, $result );

		/** @var array<string, mixed> $data */
		$data = $result->get_data();

		$this->assertSame( 0, $data['retention_count'] );
	}

	public function testAPartialBodyLeavesTheOtherFieldsAlone(): void {
		$this->put(
			[
				'default_profile' => BackupProfile::DatabaseOnly->value,
				'exclusions'      => [ 'wp-content/uploads/huge/**' ],
				'retention_count' => 9,
			]
		);

		$this->put( [ 'retention_count' => 3 ] );

		$data = $this->get();

		$this->assertSame( 3, $data['retention_count'] );
		$this->assertSame( BackupProfile::DatabaseOnly->value, $data['default_profile'] );
		$this->assertSame( [ 'wp-content/uploads/huge/**' ], $data['exclusions'] );
	}

	public function testAnEmptyArrayClearsTheExclusionsBecauseItIsNotTheSameAsAbsent(): void {
		$this->put( [ 'exclusions' => [ 'a/**' ] ] );
		$this->put( [ 'exclusions' => [] ] );

		$this->assertSame( [], $this->get()['exclusions'] );
	}

	public function testANonStringExclusionIsDroppedRatherThanFatal(): void {
		$this->put( [ 'exclusions' => [ 'wp-content/cache/**', 42, null, [ 'nested' ], '  spaced/**  ' ] ] );

		$this->assertSame(
			[ 'wp-content/cache/**', 'spaced/**' ],
			$this->get()['exclusions']
		);
	}

	public function testTheResponseCarriesTheRulesTheFormHasToExplain(): void {
		$data = $this->get();

		$this->assertSame( Settings::MIN_MAX_VOLUME_BYTES, $data['min_volume_bytes'] );
		$this->assertSame( 0, $data['retention_never'] );
		$this->assertCount( count( BackupProfile::cases() ), $data['profiles'] );
	}

	/**
	 * Spec §6.3: the opt-in is per job and never sticky.
	 *
	 * The strong form of that guarantee is not "the client clears the box" but
	 * "there is nowhere durable for the choice to live".  This asserts the
	 * absence, at the one surface that persists anything.
	 */
	public function testTheSettingsPayloadHasNoWpConfigFieldAtAll(): void {
		$data = $this->get();

		$this->assertArrayNotHasKey( BackupJob::OPTION_INCLUDE_WP_CONFIG, $data );

		$this->put( [ BackupJob::OPTION_INCLUDE_WP_CONFIG => true ] );

		$stored = get_option( SettingsRepository::OPTION_NAME, [] );

		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( BackupJob::OPTION_INCLUDE_WP_CONFIG, $stored );
	}

	public function testEverySettingsWriteGoesToThePrefixedOption(): void {
		$this->put( [ 'retention_count' => 2 ] );

		foreach ( fiction_drafts_test_option_calls()['update'] as $call ) {
			$this->assertStringStartsWith( 'fiction_drafts_', $call['option'] );
		}

		foreach ( fiction_drafts_test_option_calls()['add'] as $call ) {
			$this->assertStringStartsWith( 'fiction_drafts_', $call['option'] );
		}
	}

	public function testTheRouteRegistersUnderThePluginNamespaceWithThePermissionCheck(): void {
		$this->controller->registerRoutes();

		$routes = fiction_drafts_test_routes();

		$this->assertCount( 1, $routes );
		$this->assertSame( 'fiction-drafts/v1', $routes[0]['namespace'] );
		$this->assertSame( '/settings', $routes[0]['route'] );

		foreach ( $routes[0]['args'] as $definition ) {
			$this->assertSame(
				[ $this->controller, 'permissionCheck' ],
				$definition['permission_callback']
			);
		}
	}
}
