<?php

declare( strict_types=1 );

namespace FictionDrafts\Rest;

use FictionDrafts\Backup\JobManager;
use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Start, watch, and cancel one backup job.
 *
 * The controller's whole job is translation: HTTP in, JobManager calls out,
 * and JobManager's refusal reasons mapped to status codes.  No rule about when
 * a job may start lives here, because the WP-CLI command planned for v0.2.0
 * has to obey the same rules without duplicating them.
 */
final class JobsController extends AbstractController {


	public function __construct(
		private readonly JobManager $jobs,
		private readonly StageRegistry $stages
	) {}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/jobs',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args'                => [
					'profile'            => [
						'type'              => 'string',
						'required'          => false,
						'default'           => BackupProfile::Full->value,
						'sanitize_callback' => 'sanitize_key',
					],
					'include_wp_config'  => [
						'type'    => 'boolean',
						'default' => false,
					],
					'include_database'   => [
						'type'    => 'boolean',
						'default' => false,
					],
					'include_core'       => [
						'type'    => 'boolean',
						'default' => false,
					],
					'include_uploads'    => [
						'type'    => 'boolean',
						'default' => false,
					],
					// The only one of these that defaults to true.  Transients
					// are a cache with an expiry already in the row; a copy of
					// the site does not need last week's cached HTTP responses.
					'exclude_transients' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/active',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'active' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/' . parent::UUID_PATTERN,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/' . parent::UUID_PATTERN,
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$profile = BackupProfile::tryFrom( (string) $request->get_param( 'profile' ) );

		if ( null === $profile ) {
			return $this->error(
				'fiction_drafts_unknown_profile',
				__( 'That backup profile does not exist.', 'fiction-drafts' ),
				400
			);
		}

		try {
			$job = $this->jobs->create( $profile, $this->optionsFrom( $request ) );
		} catch ( RuntimeException $refusal ) {
			return $this->refusal( $refusal );
		}

		return $this->respond(
			[
				'uuid'   => $job->uuid,
				'status' => $job->status->value,
			],
			202
		);
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$job = $this->jobs->find( (string) $request->get_param( 'uuid' ) );

		if ( null === $job ) {
			return $this->error(
				'fiction_drafts_job_not_found',
				__( 'That backup job does not exist.', 'fiction-drafts' ),
				404
			);
		}

		return $this->respond( $this->present( $job ) );
	}

	/**
	 * The queued-or-running job, if there is one.
	 *
	 * At most one job is ever active — `JobManager::create()` refuses a second
	 * one while the first is not terminal — so this is the one thing the
	 * Backups tab needs to know to offer a link back to it, without the client
	 * having to already know a uuid to ask for.
	 */
	public function active(): WP_REST_Response {
		$job = $this->jobs->active();

		return $this->respond( [ 'job' => null === $job ? null : $this->present( $job ) ] );
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ) {
		$job = $this->jobs->cancel( (string) $request->get_param( 'uuid' ) );

		if ( null === $job ) {
			return $this->error(
				'fiction_drafts_job_not_found',
				__( 'That backup job does not exist.', 'fiction-drafts' ),
				404
			);
		}

		return $this->respond( $this->present( $job ) );
	}

	/**
	 * JobManager's refusal reasons, as status codes.
	 */
	private function refusal( RuntimeException $refusal ): WP_Error {
		return match ( $refusal->getMessage() ) {
			JobManager::REASON_ALREADY_ACTIVE => $this->error(
				'fiction_drafts_job_active',
				__( 'A backup is already running. Wait for it to finish, or cancel it first.', 'fiction-drafts' ),
				409
			),
			JobManager::REASON_NOTHING_SELECTED => $this->error(
				'fiction_drafts_nothing_selected',
				__( 'This backup would copy nothing. Select at least one of database, site files, or uploads.', 'fiction-drafts' ),
				422
			),
			default => $this->error(
				'fiction_drafts_job_refused',
				__( 'The backup could not be started.', 'fiction-drafts' ),
				400
			),
		};
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return array<string, mixed>
	 */
	private function optionsFrom( WP_REST_Request $request ): array {
		return [
			BackupJob::OPTION_INCLUDE_WP_CONFIG  => (bool) $request->get_param( 'include_wp_config' ),
			BackupJob::OPTION_INCLUDE_DATABASE   => (bool) $request->get_param( 'include_database' ),
			BackupJob::OPTION_INCLUDE_CORE       => (bool) $request->get_param( 'include_core' ),
			BackupJob::OPTION_INCLUDE_UPLOADS    => (bool) $request->get_param( 'include_uploads' ),
			BackupJob::OPTION_EXCLUDE_TRANSIENTS => (bool) $request->get_param( 'exclude_transients' ),
		];
	}

	/**
	 * The job as the dashboard sees it.
	 *
	 * No filesystem paths: the client asks for a job by uuid and a volume by
	 * sequence, and never learns or supplies a path — spec 10.2.
	 *
	 * @return array<string, mixed>
	 */
	private function present( BackupJob $job ): array {
		$stage = null === $job->stage ? null : $this->stages->find( $job, $job->stage );

		return [
			'uuid'            => $job->uuid,
			'status'          => $job->status->value,
			'profile'         => $job->profile->value,
			'stage'           => $job->stage,
			'stage_label'     => null === $stage ? null : $stage->label(),
			'processed'       => $job->processed,
			'total'           => $job->total,
			'percent'         => $job->percent(),
			// `processed` and `total` are per-stage — the runner resets both at
			// every stage boundary, because rows and files are not the same
			// unit.  Naming them again as `stage_*` is not duplication; it is
			// the payload saying which of the two numbers below is which, so a
			// dashboard cannot mistake a stage's 40% for the job's.
			'stage_processed' => $job->processed,
			'stage_total'     => $job->total,
			'overall_percent' => $this->overallPercent( $job ),
			'error'           => $job->error,
			'created_at'      => $job->createdAt,
		];
	}

	/**
	 * Progress across the whole job, which never decreases.
	 *
	 * Derived from position in the pipeline plus progress within the current
	 * stage, so finishing the database stage of a three-stage job reads 33%
	 * and the file stage then starts from there.  Without this the bar drops to
	 * zero at every boundary, which reads as "the backup restarted itself".
	 */
	private function overallPercent( BackupJob $job ): ?int {
		if ( JobStatus::Completed === $job->status ) {
			return 100;
		}

		$pipeline = $this->stages->applicableTo( $job );
		$count    = count( $pipeline );

		if ( 0 === $count || null === $job->stage ) {
			return null;
		}

		$position = 0;

		foreach ( $pipeline as $index => $stage ) {
			if ( $stage->id() === $job->stage ) {
				$position = $index;

				break;
			}
		}

		$within = ( $job->total > 0 ) ? min( 1.0, $job->processed / $job->total ) : 0.0;

		return (int) min( 100, floor( ( ( $position + $within ) / $count ) * 100 ) );
	}
}
