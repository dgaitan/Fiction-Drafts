<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Download\DownloadGrant;
use FictionDrafts\Download\DownloadHandler;
use FictionDrafts\Download\OptionGrantStore;
use FictionDrafts\Rest\AbstractController;
use FictionDrafts\Rest\DownloadController;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\InMemoryVolumeStore;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /backups/{uuid}/download-token`.
 */
final class DownloadControllerTest extends TestCase {

	private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private const USER = 7;

	private TempTree $tree;

	private StorageLocator $storage;

	private InMemoryJobStore $jobs;

	private InMemoryVolumeStore $volumes;

	private OptionGrantStore $grants;

	private DownloadController $controller;

	protected function setUp(): void {
		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_rest();
		fiction_drafts_test_set_logged_in( true );
		fiction_drafts_test_set_capability( AbstractController::CAPABILITY, true );
		fiction_drafts_test_set_user( self::USER );

		$this->tree    = new TempTree( 'fd-download' );
		$this->storage = new StorageLocator( $this->tree->root );
		$this->storage->ensure();

		$this->jobs    = new InMemoryJobStore();
		$this->volumes = new InMemoryVolumeStore();
		$this->grants  = new OptionGrantStore();

		$this->controller = new DownloadController(
			$this->jobs,
			$this->volumes,
			$this->storage,
			$this->grants
		);
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	private function seed( JobStatus $status = JobStatus::Completed, bool $onDisk = true ): BackupJob {
		$job = $this->jobs->insert(
			new BackupJob(
				uuid: self::UUID,
				profile: BackupProfile::Full,
				status: $status,
				createdAt: '2026-08-28 10:00:00',
				completedAt: '2026-08-28 10:05:00'
			)
		);

		$naming = new VolumeNaming( $this->storage->baseDir() );
		$name   = $naming->filenameFor( $job, 1 );

		if ( $onDisk ) {
			file_put_contents( $naming->pathFor( $job, 1 ), str_repeat( 'z', 2048 ) );
		}

		$this->volumes->replaceFor(
			$job,
			[ new ArchiveVolume( self::UUID, 1, $name, '', 2048, str_repeat( 'c', 64 ) ) ]
		);

		return $job;
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function issue( array $params = [] ): WP_REST_Response|WP_Error {
		$request = new WP_REST_Request(
			[
				'uuid'   => $params['uuid'] ?? self::UUID,
				'volume' => $params['volume'] ?? 1,
			],
			'POST'
		);

		return $this->controller->issue( $request );
	}

	public function testAGrantIsIssuedForACompletedBackup(): void {
		$this->seed();

		$response = $this->issue();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	public function testTheResponseCarriesAUrlAndAnExpiry(): void {
		$this->seed();

		$data = $this->issue()->get_data();

		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'expires_at', $data );
		$this->assertSame( DownloadGrant::TTL_SECONDS, $data['expires_in'] );
	}

	public function testTheExpiryIsFiveMinutesOutAndSaysWhichZone(): void {
		$this->seed();

		$data = $this->issue()->get_data();

		$stamp = strtotime( (string) $data['expires_at'] );

		$this->assertNotFalse( $stamp );
		// Within a second of five minutes: the assertion is about the interval,
		// not about the exact instant the test happened to run.
		$this->assertEqualsWithDelta( time() + DownloadGrant::TTL_SECONDS, $stamp, 2 );
		// RFC 3339 with an explicit offset. A bare local timestamp is read as a
		// different instant in every browser that parses it.
		$this->assertMatchesRegularExpression( '/(Z|[+-]\d{2}:\d{2})$/', (string) $data['expires_at'] );
	}

	public function testTheUrlCarriesEveryParameterTheHandlerNeeds(): void {
		$this->seed();

		$url = (string) $this->issue()->get_data()['url'];

		foreach ( [ 'action=' . DownloadHandler::ACTION, 'job=' . self::UUID, 'volume=1', 'token=', '_wpnonce=' ] as $part ) {
			$this->assertStringContainsString( $part, $url, $part . ' is missing from the download URL' );
		}
	}

	/**
	 * The client is handed a finished URL and appends nothing.
	 */
	public function testTheUrlPointsAtAdminPost(): void {
		$this->seed();

		$this->assertStringContainsString( 'admin-post.php', (string) $this->issue()->get_data()['url'] );
	}

	/**
	 * The URL is JSON, not HTML.
	 *
	 * `wp_nonce_url()` ends in `esc_html()`, which turns every `&` into `&amp;`.
	 * A browser sent to that URL asks for `amp;job` and `amp;token`, the handler
	 * sees a request with no job and no nonce, and every download in the plugin
	 * returns 403. This test exists because that shipped past a whole suite of
	 * unit tests: the double for `wp_nonce_url()` did not escape, so the code
	 * was only ever proved correct against the double.
	 */
	public function testTheUrlIsNotHtmlEscaped(): void {
		$this->seed();

		$url = (string) $this->issue()->get_data()['url'];

		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertStringNotContainsString( '&#0', $url );
		// The control: there are separators to have escaped in the first place.
		$this->assertGreaterThanOrEqual( 4, substr_count( $url, '&' ) );
	}

	/**
	 * The parameters survive being read the way a browser hands them over.
	 */
	public function testTheUrlParsesBackIntoTheParametersTheHandlerReads(): void {
		$this->seed();

		$url = (string) $this->issue()->get_data()['url'];

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		foreach ( [ 'action', 'job', 'volume', 'token', '_wpnonce' ] as $param ) {
			$this->assertArrayHasKey( $param, $query, $url );
		}

		$this->assertSame( self::UUID, $query['job'] );
	}

	public function testTheUrlNeverContainsAPath(): void {
		$this->seed();

		$url = (string) $this->issue()->get_data()['url'];

		$this->assertStringNotContainsString( $this->storage->baseDir(), $url );
		$this->assertStringNotContainsString( '.zip', $url );
	}

	public function testTheTokenInTheUrlIsTheOneThatWorks(): void {
		$this->seed();

		$url = (string) $this->issue()->get_data()['url'];

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$grant = $this->grants->consume( (string) ( $query['token'] ?? '' ) );

		$this->assertNotNull( $grant );
		$this->assertTrue( $grant->authorises( self::UUID, 1, self::USER ) );
	}

	public function testTheGrantIsBoundToTheIssuingUser(): void {
		$this->seed();

		$url = (string) $this->issue()->get_data()['url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$grant = $this->grants->consume( (string) ( $query['token'] ?? '' ) );

		$this->assertNotNull( $grant );
		$this->assertFalse( $grant->authorises( self::UUID, 1, 99 ) );
	}

	public function testAnUnknownBackupIsNotFound(): void {
		$response = $this->issue( [ 'uuid' => 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee' ] );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function testAnUnfinishedBackupIsAConflictRatherThanANotFound(): void {
		$this->seed( JobStatus::Running );

		$response = $this->issue();

		$this->assertInstanceOf( WP_Error::class, $response );
		// The backup exists; the answer is "not yet". A volume still being
		// written has no central directory, so it is not a zip at all.
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}

	public function testAVolumeOutsideTheBackupIsNotFound(): void {
		$this->seed();

		foreach ( [ 0, 2, 9999 ] as $sequence ) {
			$response = $this->issue( [ 'volume' => $sequence ] );

			$this->assertInstanceOf( WP_Error::class, $response, 'sequence ' . $sequence );
			$this->assertSame( 404, $response->get_error_data()['status'] );
		}
	}

	public function testAVolumeMissingFromDiskIsRefusedBeforeAGrantIsSpent(): void {
		$this->seed( JobStatus::Completed, false );

		$response = $this->issue();

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
		// And nothing was minted: a link that will 404 in five seconds is a
		// worse answer than saying so now.
		$this->assertSame( [], get_option( OptionGrantStore::OPTION, [] ) );
	}

	public function testTheRouteIsRegisteredUnderTheNamespace(): void {
		$this->controller->registerRoutes();

		$found = $this->downloadRoutes();

		$this->assertCount( 1, $found );
		$this->assertSame( AbstractController::NAMESPACE, $found[0]['namespace'] );
	}

	public function testTheRouteIsAPostBecauseItMintsACredential(): void {
		$this->controller->registerRoutes();

		$found = $this->downloadRoutes();

		$this->assertNotEmpty( $found, 'the control: the route was registered at all' );

		// A GET that mutates state is prefetchable: a browser or a link-scanner
		// walking the admin screen would spend grants nobody asked for.
		$this->assertSame( 'POST', $found[0]['args']['methods'] );
	}

	public function testTheRouteMatchesOnlyAWellFormedUuid(): void {
		$this->controller->registerRoutes();

		$route = $this->downloadRoutes()[0]['route'];

		$this->assertSame( 1, preg_match( '#^/backups/(.+)/download-token$#', $route, $parts ) );
		// The uuid segment is a hex pattern, not a wildcard: a traversal
		// sequence cannot reach the callback at all.
		$this->assertSame( 1, preg_match( '/\[a-f0-9\]/', $parts[1] ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function downloadRoutes(): array {
		return array_values(
			array_filter(
				fiction_drafts_test_routes(),
				static fn ( array $route ): bool => str_contains( (string) $route['route'], 'download-token' )
			)
		);
	}

	public function testTheRouteRefusesWithoutTheCapability(): void {
		$this->seed();
		fiction_drafts_test_set_capability( AbstractController::CAPABILITY, false );

		$request = new WP_REST_Request( [ 'uuid' => self::UUID ], 'POST' );

		$this->assertInstanceOf( WP_Error::class, $this->controller->permissionCheck( $request ) );
	}

	/**
	 * The control for the refusal above.
	 */
	public function testTheRouteAllowsWithTheCapability(): void {
		$this->seed();

		$request = new WP_REST_Request( [ 'uuid' => self::UUID ], 'POST' );

		$this->assertTrue( $this->controller->permissionCheck( $request ) );
	}

	public function testOnMultisiteASiteAdministratorIsRefused(): void {
		$this->seed();
		$GLOBALS['fiction_drafts_test_multisite'] = true;
		fiction_drafts_test_set_capability( 'manage_network_options', false );

		$request = new WP_REST_Request( [ 'uuid' => self::UUID ], 'POST' );

		$this->assertInstanceOf( WP_Error::class, $this->controller->permissionCheck( $request ) );

		$GLOBALS['fiction_drafts_test_multisite'] = false;
	}
}
