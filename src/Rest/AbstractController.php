<?php

declare( strict_types=1 );

namespace FictionDrafts\Rest;

use WP_Error;
use WP_REST_Request;

/**
 * The capability gate every route passes through.
 *
 * A backup archive contains every user's password hash and every credential a
 * plugin has stored in options.  There is no read-only view of that worth
 * offering to a lesser role, so the whole namespace is `manage_options` and
 * the check lives in one place rather than being repeated per route.
 */
abstract class AbstractController {

	public const NAMESPACE = 'fiction-drafts/v1';

	public const CAPABILITY = 'manage_options';

	abstract public function registerRoutes(): void;

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return true|WP_Error
	 */
	public function permissionCheck( WP_REST_Request $request ) {
		if ( ! self::hasCapability() ) {
			return new WP_Error(
				'fiction_drafts_forbidden',
				__( 'You do not have permission to manage backups.', 'fiction-drafts' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * The capability, scoped for multisite.
	 *
	 * `manage_options` is granted per site. On a network-activated install
	 * every subsite administrator holds it, while the job rows and the storage
	 * directory belong to whichever site is being served — so a subsite
	 * administrator could list, download, and delete the main site's archives.
	 * Spec §10.2 recorded that as a known gap; this closes it.
	 *
	 * On multisite the requirement is `manage_network_options`. That is
	 * stricter than a network where the plugin was activated for a single site
	 * needs, and it will refuse a site administrator there. It is the right way
	 * to be wrong: this release does not claim multisite support, the readme
	 * says so, and the failure mode is a refused request rather than another
	 * site's password hashes.
	 */
	public static function hasCapability(): bool {
		if ( is_multisite() ) {
			return current_user_can( 'manage_network_options' );
		}

		return current_user_can( self::CAPABILITY );
	}

	/**
	 * @param array<string, mixed> $data   Response body.
	 * @param int                  $status HTTP status code.
	 */
	protected function respond( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	protected function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
