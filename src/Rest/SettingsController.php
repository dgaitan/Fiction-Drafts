<?php

declare( strict_types=1 );

namespace FictionDrafts\Rest;

use FictionDrafts\Admin\ProfileCatalogue;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\ExclusionSet;
use FictionDrafts\Domain\Settings;
use FictionDrafts\Persistence\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reads and writes the administrator's standing preferences.
 *
 * ## A partial body edits, it does not replace
 *
 * `PUT` is nominally "replace the resource", and a literal reading would blank
 * every field the client left out.  The screen has three groups on it and a
 * client that saves one of them is the normal case, so every field is optional
 * and an absent field means "leave it alone".  A client that genuinely wants
 * to clear the exclusion list sends an empty array, which is a different thing
 * from not sending the key at all.
 *
 * ## Out-of-range values are corrected, not refused
 *
 * `Settings::create()` clamps, for a reason recorded there: these values come
 * from a form and from option rows written years ago, and a backup plugin that
 * refuses to load its own settings is worse than one that corrects them.  The
 * response therefore returns what was *stored*, not what was *sent*, so a form
 * that typed 5 MiB immediately shows the 10 MiB floor it actually got.
 *
 * An unknown profile is the one exception and is a `400`.  Clamping a number
 * to the nearest legal value preserves the intent; silently turning an
 * unrecognised profile into `Full` would turn "database only" into a full-site
 * archive, which is the opposite of the intent and is not visible anywhere.
 */
final class SettingsController extends AbstractController {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ProfileCatalogue $profiles
	) {}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'show' ],
					'permission_callback' => [ $this, 'permissionCheck' ],
				],
				[
					'methods'             => 'PUT, PATCH',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'permissionCheck' ],
					'args'                => [
						'default_profile'  => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						],
						'exclusions'       => [
							'type'     => 'array',
							'required' => false,
							'items'    => [ 'type' => 'string' ],
						],
						'max_volume_bytes' => [
							'type'     => 'integer',
							'required' => false,
						],
						'retention_count'  => [
							'type'     => 'integer',
							'required' => false,
						],
					],
				],
			]
		);
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( $this->present( $this->settings->get() ) );
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$settings = $this->settings->get();

		if ( null !== $request->get_param( 'default_profile' ) ) {
			$profile = BackupProfile::tryFrom( (string) $request->get_param( 'default_profile' ) );

			if ( null === $profile ) {
				return $this->error(
					'fiction_drafts_unknown_profile',
					__( 'That backup profile does not exist.', 'fiction-drafts' ),
					400
				);
			}

			$settings = $settings->withDefaultProfile( $profile );
		}

		if ( null !== $request->get_param( 'exclusions' ) ) {
			$settings = $settings->withExclusions( self::exclusionsFrom( $request->get_param( 'exclusions' ) ) );
		}

		if ( null !== $request->get_param( 'max_volume_bytes' ) ) {
			$settings = $settings->withMaxVolumeBytes( (int) $request->get_param( 'max_volume_bytes' ) );
		}

		if ( null !== $request->get_param( 'retention_count' ) ) {
			$settings = $settings->withRetentionCount( (int) $request->get_param( 'retention_count' ) );
		}

		if ( ! $this->settings->save( $settings ) ) {
			return $this->error(
				'fiction_drafts_settings_not_saved',
				__( 'The settings could not be saved.', 'fiction-drafts' ),
				500
			);
		}

		// Read back rather than echoing the object just built.  The repository
		// drops its cache on a failed write, so a re-read is the difference
		// between reporting what was stored and reporting what was intended.
		$this->settings->flush();

		return $this->respond( $this->present( $this->settings->get() ) );
	}

	/**
	 * Patterns from a request body, one at a time.
	 *
	 * Filtering a non-string entry rather than rejecting the request keeps a
	 * malformed row in a decade-old option from making the settings screen
	 * unusable — the same reasoning as `Settings::fromArray()`, applied at the
	 * other end.  `sanitize_text_field` would strip nothing a glob needs and
	 * removes control characters and tags.
	 *
	 * @param  mixed $raw Whatever arrived under the `exclusions` key.
	 * @return ExclusionSet
	 */
	private static function exclusionsFrom( mixed $raw ): ExclusionSet {
		if ( ! is_array( $raw ) ) {
			return new ExclusionSet();
		}

		$patterns = [];

		foreach ( $raw as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}

			$pattern = trim( sanitize_text_field( $candidate ) );

			if ( '' !== $pattern ) {
				$patterns[] = $pattern;
			}
		}

		return new ExclusionSet( $patterns );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( Settings $settings ): array {
		return [
			'default_profile'  => $settings->defaultProfile()->value,
			'exclusions'       => $settings->exclusions()->patterns(),
			'max_volume_bytes' => $settings->maxVolumeBytes(),
			'retention_count'  => $settings->retentionCount(),
			// The rules the form has to explain, sent rather than restated.
			// A client that hardcodes "10 MiB minimum" is a second copy of a
			// constant that already exists, and the copy nothing tests is the
			// one on screen.
			'min_volume_bytes' => Settings::MIN_MAX_VOLUME_BYTES,
			'retention_never'  => 0,
			'profiles'         => $this->profiles->all(),
		];
	}
}
