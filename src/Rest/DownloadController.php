<?php

declare( strict_types=1 );

namespace FictionDrafts\Rest;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Download\DownloadGrant;
use FictionDrafts\Download\DownloadHandler;
use FictionDrafts\Download\GrantStore;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Storage\StorageLocator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Issues permission to download one volume.
 *
 * `POST /backups/{uuid}/download-token` → `{ url, expires_at, volume, filename }`.
 *
 * ## Why the URL is built here and not in the client
 *
 * The client is handed a finished URL, nonce and all, and appends nothing to
 * it.  A client that composed the URL would be a second place that knows the
 * action name, the parameter names, and the nonce action — and the first time
 * those drift, the failure is a `403` on a download that used to work, with
 * nothing pointing at the cause.  Composing it server-side also means the
 * client never holds enough to construct a URL for a volume it was not given
 * permission to.
 *
 * ## Why issuing is a POST
 *
 * It mints a credential and mutates stored state.  A `GET` that does that is
 * prefetchable — a browser, a link-scanner, or an over-eager extension walking
 * the admin screen would spend grants the administrator never asked for.
 */
final class DownloadController extends AbstractController {


	public function __construct(
		private readonly JobStore $jobs,
		private readonly VolumeStore $volumes,
		private readonly StorageLocator $storage,
		private readonly GrantStore $grants
	) {}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/backups/' . parent::UUID_PATTERN . '/download-token',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'issue' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args'                => [
					'volume' => [
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue( WP_REST_Request $request ) {
		$uuid = (string) $request->get_param( 'uuid' );
		$job  = $this->jobs->findByUuid( $uuid );

		if ( null === $job ) {
			return $this->error(
				'fiction_drafts_backup_not_found',
				__( 'That backup does not exist.', 'fiction-drafts' ),
				404
			);
		}

		// A running job's volumes are being written to right now, and the one
		// the archive stage currently holds open has no central directory yet —
		// downloading it produces a file that is not a zip. 409 rather than 404
		// because the backup exists and the answer is "not yet".
		if ( JobStatus::Completed !== $job->status ) {
			return $this->error(
				'fiction_drafts_backup_incomplete',
				__( 'That backup is not finished yet.', 'fiction-drafts' ),
				409
			);
		}

		$sequence = (int) $request->get_param( 'volume' );
		$volume   = $this->volumeFor( $job, $sequence );

		if ( null === $volume ) {
			return $this->error(
				'fiction_drafts_volume_not_found',
				__( 'That volume is not part of this backup.', 'fiction-drafts' ),
				404
			);
		}

		// Checked before a grant is spent rather than after, so a backup whose
		// files were removed by hand answers honestly instead of handing over a
		// link that will 404 in five seconds.
		if ( ! is_file( VolumeNaming::forStorage( $this->storage )->pathFor( $job, $sequence ) ) ) {
			return $this->error(
				'fiction_drafts_volume_missing',
				__( 'That volume is no longer on this server.', 'fiction-drafts' ),
				404
			);
		}

		$token = $this->grants->issue( $job->uuid, $sequence, get_current_user_id() );

		return $this->respond(
			[
				'url'        => $this->urlFor( $job, $sequence, $token ),
				'expires_at' => gmdate( 'c', time() + DownloadGrant::TTL_SECONDS ),
				'expires_in' => DownloadGrant::TTL_SECONDS,
				'volume'     => $sequence,
				'filename'   => $volume->filename,
				'bytes'      => $volume->bytes,
			]
		);
	}

	/**
	 * The finished download URL.
	 *
	 * `admin_url()` rather than a constructed path, so a site whose admin lives
	 * somewhere other than `/wp-admin/` still gets a URL that resolves.
	 *
	 * ## Why not `wp_nonce_url()`
	 *
	 * Because it escapes for HTML. `wp_nonce_url()` ends in `esc_html()`, which
	 * is right for a URL about to be printed into an `href` and wrong for one
	 * being handed to a client as JSON: every `&` comes back as `&amp;`, so a
	 * browser navigating to it sends parameters named `amp;job`, `amp;volume`
	 * and `amp;token`. The handler then sees a request with no job, no volume,
	 * and no nonce, and refuses it — every download, for every user, with a
	 * message about the link being invalid.
	 *
	 * Measured, not theorised: this was written with `wp_nonce_url()` first, and
	 * the unit tests passed, because the test double for it did not escape. The
	 * live run against real WordPress returned `403` on the control.
	 */
	private function urlFor( BackupJob $job, int $sequence, string $token ): string {
		return add_query_arg(
			[
				'action'   => DownloadHandler::ACTION,
				'job'      => $job->uuid,
				'volume'   => $sequence,
				'token'    => $token,
				'_wpnonce' => wp_create_nonce( DownloadHandler::NONCE_ACTION ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	private function volumeFor( BackupJob $job, int $sequence ): ?\FictionDrafts\Domain\ArchiveVolume {
		if ( $sequence < 1 || $sequence > VolumeNaming::MAX_VOLUMES ) {
			return null;
		}

		foreach ( $this->volumes->allFor( $job ) as $volume ) {
			if ( $volume->sequence === $sequence ) {
				return $volume;
			}
		}

		return null;
	}
}
