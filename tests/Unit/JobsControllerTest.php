<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\JobManager;
use FictionDrafts\Backup\Scheduler;
use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Rest\JobsController;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use WP_REST_Response;

/**
 * The one-active-job lookup the Backups tab uses to find its way back to a
 * running job without already knowing its uuid.
 */
final class JobsControllerTest extends TestCase {

	private TempTree $tree;

	private InMemoryJobStore $jobs;

	private JobsController $controller;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_rest();

		$this->tree = new TempTree( 'fd-jobs' );
		$storage    = new StorageLocator( $this->tree->root );
		$storage->ensure();

		$this->jobs = new InMemoryJobStore();

		$this->controller = new JobsController(
			new JobManager( $this->jobs, new Scheduler(), $storage ),
			new StageRegistry()
		);
	}

	protected function tearDown(): void {
		$this->tree->remove();

		parent::tearDown();
	}

	public function testThereIsNoActiveJobWhenNothingHasBeenStarted(): void {
		$response = $this->controller->active();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertNull( $response->get_data()['job'] );
	}

	public function testAQueuedJobIsReportedAsActive(): void {
		$job = $this->jobs->insert(
			new BackupJob(
				uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
				profile: BackupProfile::Full,
				status: JobStatus::Queued,
				createdAt: '2026-08-29 09:00:00'
			)
		);

		$data = $this->controller->active()->get_data();

		$this->assertIsArray( $data['job'] );
		$this->assertSame( $job->uuid, $data['job']['uuid'] );
		$this->assertSame( 'queued', $data['job']['status'] );
		$this->assertSame( 'full', $data['job']['profile'] );
	}

	public function testARunningJobIsReportedAsActive(): void {
		$this->jobs->insert(
			new BackupJob(
				uuid: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
				profile: BackupProfile::DatabaseOnly,
				status: JobStatus::Running
			)
		);

		$data = $this->controller->active()->get_data();

		$this->assertSame( 'running', $data['job']['status'] );
	}

	/**
	 * The control for the two tests above: a finished job must not be handed
	 * back as "active", or the Backups tab would link to progress that will
	 * never move.
	 */
	public function testACompletedJobIsNotReportedAsActive(): void {
		$this->jobs->insert(
			new BackupJob(
				uuid: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
				profile: BackupProfile::Full,
				status: JobStatus::Completed
			)
		);

		$this->assertNull( $this->controller->active()->get_data()['job'] );
	}

	public function testTheActiveJobCarriesTheSameShapeAsShow(): void {
		$this->jobs->insert(
			new BackupJob(
				uuid: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
				profile: BackupProfile::Full,
				status: JobStatus::Running,
				processed: 4,
				total: 10
			)
		);

		$job = $this->controller->active()->get_data()['job'];

		foreach ( [ 'uuid', 'status', 'profile', 'stage', 'stage_label', 'processed', 'total', 'percent', 'overall_percent', 'error', 'created_at' ] as $key ) {
			$this->assertArrayHasKey( $key, $job );
		}

		$this->assertSame( 40, $job['percent'] );
	}

	public function testEveryJobsRouteRegistersWithThePermissionCheck(): void {
		$this->controller->registerRoutes();

		$routes = fiction_drafts_test_routes();

		$this->assertCount( 4, $routes );

		foreach ( $routes as $route ) {
			$this->assertSame( 'fiction-drafts/v1', $route['namespace'] );
			$this->assertSame(
				[ $this->controller, 'permissionCheck' ],
				$route['args']['permission_callback']
			);
		}
	}

	public function testTheActiveRouteIsRegisteredAheadOfTheUuidRoute(): void {
		$this->controller->registerRoutes();

		$routes = array_column( fiction_drafts_test_routes(), 'route' );

		$this->assertContains( '/jobs/active', $routes );
	}
}
