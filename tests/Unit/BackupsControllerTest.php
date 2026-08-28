<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Admin\ProfileCatalogue;
use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Backup\BackupRemover;
use FictionDrafts\Backup\Manifest;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Rest\BackupsController;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\InMemoryVolumeStore;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The backups list and the delete route.
 */
final class BackupsControllerTest extends TestCase {

	private TempTree $tree;

	private StorageLocator $storage;

	private InMemoryJobStore $jobs;

	private InMemoryVolumeStore $volumes;

	private BackupsController $controller;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_rest();

		$this->tree    = new TempTree( 'fd-backups' );
		$this->storage = new StorageLocator( $this->tree->root );
		$this->storage->ensure();

		$this->jobs    = new InMemoryJobStore();
		$this->volumes = new InMemoryVolumeStore();

		$this->controller = new BackupsController(
			$this->jobs,
			$this->volumes,
			$this->storage,
			new BackupRemover( $this->jobs, $this->volumes, $this->storage ),
			new ProfileCatalogue()
		);
	}

	protected function tearDown(): void {
		$this->tree->remove();

		parent::tearDown();
	}

	private function naming(): VolumeNaming {
		return new VolumeNaming( $this->storage->baseDir() );
	}

	/**
	 * A completed backup with real files on disk.
	 *
	 * @param int $volumeCount How many volumes to seal.
	 */
	private function seedCompleted( string $uuid, int $volumeCount = 2, bool $withManifest = true ): BackupJob {
		$job = $this->jobs->insert(
			new BackupJob(
				uuid: $uuid,
				profile: BackupProfile::Full,
				status: JobStatus::Completed,
				createdAt: '2026-08-28 10:00:00',
				completedAt: '2026-08-28 10:04:00'
			)
		);

		$sealed = [];
		$total  = 0;

		for ( $sequence = 1; $sequence <= $volumeCount; $sequence++ ) {
			$path     = $this->naming()->pathFor( $job, $sequence );
			$contents = str_repeat( 'v' . $sequence, 512 );

			file_put_contents( $path, $contents );

			$total   += strlen( $contents );
			$sealed[] = new ArchiveVolume(
				jobUuid: $job->uuid,
				sequence: $sequence,
				filename: basename( $path ),
				path: '',
				bytes: strlen( $contents ),
				sha256: hash( 'sha256', $contents )
			);
		}

		$this->volumes->replaceFor( $job, $sealed );

		if ( $withManifest ) {
			Manifest::write(
				$this->naming()->manifestPathFor( $job ),
				[
					'schema'             => Manifest::SCHEMA,
					'site_url'           => 'https://fiction-drafts.test',
					'includes_wp_config' => true,
					'file_count'         => 59,
					'volumes'            => [],
				]
			);
		}

		return $this->jobs->save( $job->with( [ 'sizeBytes' => $total ] ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function index(): array {
		$response = $this->controller->index( new WP_REST_Request() );

		/** @var array{backups: array<int, array<string, mixed>>} $data */
		$data = $response->get_data();

		return $data['backups'];
	}

	public function testOnlyCompletedJobsAreBackups(): void {
		$this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );

		foreach ( [ JobStatus::Queued, JobStatus::Running, JobStatus::Failed, JobStatus::Cancelled ] as $index => $status ) {
			$this->jobs->insert(
				new BackupJob(
					uuid: sprintf( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb%02d', $index ),
					profile: BackupProfile::Full,
					status: $status
				)
			);
		}

		$backups = $this->index();

		$this->assertCount( 1, $backups );
		$this->assertSame( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $backups[0]['uuid'] );
	}

	public function testAnEntryCarriesDateProfileSizeAndVolumeCount(): void {
		$this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 3 );

		$entry = $this->index()[0];

		$this->assertSame( '2026-08-28 10:00:00', $entry['created_at'] );
		$this->assertSame( BackupProfile::Full->value, $entry['profile']['slug'] );
		$this->assertSame( 'Everything', $entry['profile']['label'] );
		$this->assertSame( 3, $entry['volume_count'] );
		$this->assertGreaterThan( 0, $entry['size_bytes'] );
	}

	public function testTheHumanSizeIsFormattedServerSideAndAgreesWithTheByteCount(): void {
		$this->assertSame( '0 B', BackupsController::humanBytes( 0 ) );
		$this->assertSame( '512 B', BackupsController::humanBytes( 512 ) );
		$this->assertSame( '1.0 KiB', BackupsController::humanBytes( 1024 ) );
		$this->assertSame( '1.5 MiB', BackupsController::humanBytes( 1024 * 1024 * 3 / 2 ) );
		$this->assertSame( '2.0 GiB', BackupsController::humanBytes( 2 * 1024 ** 3 ) );

		$this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );

		$entry = $this->index()[0];

		$this->assertSame( BackupsController::humanBytes( $entry['size_bytes'] ), $entry['size_human'] );
	}

	public function testEachVolumeCarriesItsSequenceBytesAndChecksum(): void {
		$this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 2 );

		$volumes = $this->index()[0]['volumes'];

		$this->assertCount( 2, $volumes );
		$this->assertSame( 1, $volumes[0]['sequence'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $volumes[0]['sha256'] );
		$this->assertSame( 1024, $volumes[0]['bytes'] );
	}

	public function testTheManifestIsReadFromTheSidecarAndSurfacesTheCredentialFlag(): void {
		$this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );

		$entry = $this->index()[0];

		$this->assertIsArray( $entry['manifest'] );
		$this->assertSame( 59, $entry['manifest']['file_count'] );
		$this->assertTrue( $entry['includes_wp_config'] );
	}

	/**
	 * The control for the test above: with the sidecar deleted, the same
	 * assertions would be satisfied by an entry that was never enriched, so
	 * the pair proves the manifest is actually being read.
	 */
	public function testABackupWithNoSidecarIsStillListed(): void {
		$job = $this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 2, false );

		$this->assertFileDoesNotExist( $this->naming()->manifestPathFor( $job ) );

		$backups = $this->index();

		$this->assertCount( 1, $backups, 'a backup nobody can see is a backup nobody can delete' );
		$this->assertNull( $backups[0]['manifest'] );
		$this->assertSame( 2, $backups[0]['volume_count'] );
	}

	public function testAvailabilityIsDerivedFromWhatIsActuallyOnDisk(): void {
		$job = $this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 2 );

		$this->assertTrue( $this->index()[0]['available'] );

		unlink( $this->naming()->pathFor( $job, 2 ) );

		$this->assertFalse(
			$this->index()[0]['available'],
			'a row whose volumes are gone must not be offered as a download'
		);
	}

	public function testNoFieldInThePayloadIsAFilesystemPath(): void {
		$this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );

		$encoded = wp_json_encode( $this->index() );

		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( $this->storage->baseDir(), $encoded );
		$this->assertStringNotContainsString( $this->tree->root, $encoded );
	}

	public function testDeletingRemovesVolumesSidecarLedgerAndRow(): void {
		$job = $this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 2 );

		$first = $this->naming()->pathFor( $job, 1 );
		$this->assertFileExists( $first, 'the fixture must be real or the deletion proves nothing' );

		$result = $this->controller->destroy( $this->request( $job->uuid ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertFileDoesNotExist( $first );
		$this->assertFileDoesNotExist( $this->naming()->pathFor( $job, 2 ) );
		$this->assertFileDoesNotExist( $this->naming()->manifestPathFor( $job ) );
		$this->assertSame( [], $this->volumes->allFor( $job ) );
		$this->assertNull( $this->jobs->findByUuid( $job->uuid ) );
	}

	public function testDeletingFiresTheSameActionTheSweepFires(): void {
		$job = $this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );

		$this->controller->destroy( $this->request( $job->uuid ) );

		$this->assertCount( 1, fiction_drafts_test_did_action( 'fiction_drafts/backup_deleted' ) );
	}

	public function testARunningBackupIsRefusedRatherThanDeletedUnderItsWorker(): void {
		$job = $this->jobs->insert(
			new BackupJob(
				uuid: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
				profile: BackupProfile::Full,
				status: JobStatus::Running
			)
		);

		$path = $this->naming()->pathFor( $job, 1 );
		file_put_contents( $path, 'partial' );

		$result = $this->controller->destroy( $this->request( $job->uuid ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_status() );
		$this->assertFileExists( $path );
		$this->assertNotNull( $this->jobs->findByUuid( $job->uuid ) );
	}

	public function testAnUnknownUuidIsFourOhFour(): void {
		$result = $this->controller->destroy( $this->request( 'dddddddd-dddd-4ddd-8ddd-dddddddddddd' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_status() );
	}

	/**
	 * The sidecar is a file on disk, which makes it input.
	 */
	public function testAHostileSidecarIsProjectedRatherThanEchoed(): void {
		$job = $this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1, false );

		Manifest::write(
			$this->naming()->manifestPathFor( $job ),
			[
				'schema'             => Manifest::SCHEMA,
				'includes_wp_config' => false,
				'file_count'         => 7,
				'active_plugins'     => [ 'a/a.php', 'b/b.php' ],
				// None of the following may reach the browser.
				'evil'               => '<script>alert(1)</script>',
				'site_url'           => [ 'deeply' => [ 'nested' => [ 'structure' ] ] ],
				'volumes'            => array_fill( 0, 5000, 'x' ),
			]
		);

		$manifest = $this->index()[0]['manifest'];

		$this->assertIsArray( $manifest );
		$this->assertArrayNotHasKey( 'evil', $manifest, 'an unknown key reached the client' );
		$this->assertArrayNotHasKey( 'volumes', $manifest, 'the sidecar ledger must not shadow the database one' );
		$this->assertNull( $manifest['site_url'], 'a nested structure must be dropped, not flattened' );
		$this->assertSame( 7, $manifest['file_count'] );
		$this->assertSame( [ 'a/a.php', 'b/b.php' ], $manifest['active_plugins'] );
		$this->assertSame( array_keys( $manifest ), array_values( array_diff( Manifest::KEYS, [ 'volumes' ] ) ) );
	}

	public function testTheTimestampsAreAlsoOfferedUnambiguously(): void {
		$this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );

		$entry = $this->index()[0];

		$this->assertSame( '2026-08-28T10:00:00+00:00', $entry['created_at_iso'] );
		$this->assertNotNull( $entry['completed_at_iso'] );
	}

	/**
	 * A uuid is a database column, and a database column is not a promise.
	 */
	public function testAHostileUuidCannotEscapeTheStorageRootViaTheWorkingDirectory(): void {
		$outside = dirname( $this->storage->baseDir() ) . '/fd-must-survive.txt';
		file_put_contents( $outside, 'keep me' );

		$this->assertFileExists( $outside, 'the control file must exist or this proves nothing' );

		$hostile = $this->jobs->insert(
			new BackupJob(
				uuid: '../../../../../../tmp',
				profile: BackupProfile::Full,
				status: JobStatus::Completed
			)
		);

		$this->controller->destroy(
			new \WP_REST_Request( [ 'uuid' => $hostile->uuid ], 'DELETE' )
		);

		$this->assertFileExists( $outside, 'the delete escaped the storage root' );
		$this->assertDirectoryExists( $this->storage->baseDir() );

		unlink( $outside );
	}

	public function testBothRoutesRegisterWithThePermissionCheck(): void {
		$this->controller->registerRoutes();

		$routes = fiction_drafts_test_routes();

		$this->assertCount( 2, $routes );

		foreach ( $routes as $route ) {
			$this->assertSame( 'fiction-drafts/v1', $route['namespace'] );
			$this->assertSame(
				[ $this->controller, 'permissionCheck' ],
				$route['args']['permission_callback']
			);
		}
	}

	private function request( string $uuid ): WP_REST_Request {
		return new WP_REST_Request( [ 'uuid' => $uuid ], 'DELETE' );
	}

	// -------------------------------------------------------- the N+1 on list

	/**
	 * The list reads the volume ledger once, not once per backup.
	 *
	 * `per_page` goes to a hundred and this route is the first thing the screen
	 * loads, so a per-row query made the first paint a hundred round trips to
	 * MySQL. The count is asserted rather than the shape, because a batch method
	 * that loops internally satisfies the interface and keeps the N+1.
	 */
	public function testTheLedgerIsReadOncePerPageNotOncePerBackup(): void {
		foreach ( [ 'a', 'b', 'c', 'd' ] as $index => $letter ) {
			$this->seedCompleted( sprintf( '%s%s%s%s%s%s%s%s-cccc-4ccc-8ccc-cccccccccc%02d', ...array_merge( array_fill( 0, 8, $letter ), [ $index ] ) ) );
		}

		$this->volumes->batchReads = 0;

		$backups = $this->index();

		$this->assertCount( 4, $backups, 'the fixture itself must produce four backups' );
		$this->assertSame( 1, $this->volumes->batchReads );
	}

	/**
	 * The control: batching must not have flattened four ledgers into one.
	 */
	public function testEachBackupStillGetsItsOwnVolumes(): void {
		$first  = $this->seedCompleted( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1 );
		$second = $this->seedCompleted( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 3 );

		$byUuid = [];

		foreach ( $this->index() as $entry ) {
			$byUuid[ $entry['uuid'] ] = $entry;
		}

		$this->assertSame( 1, $byUuid[ $first->uuid ]['volume_count'] );
		$this->assertSame( 3, $byUuid[ $second->uuid ]['volume_count'] );

		foreach ( $byUuid[ $second->uuid ]['volumes'] as $volume ) {
			$this->assertNotSame( '', $volume['sha256'] );
		}
	}
}
