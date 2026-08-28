<?php

declare( strict_types=1 );

namespace FictionDrafts\Admin;

use FictionDrafts\Backup\StageRegistry;
use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Rest\AbstractController;

/**
 * The one admin screen, and the payload the app boots from.
 *
 * ## The page is one div
 *
 * Everything after `<div id="fiction-drafts-root">` is React's.  A PHP-rendered
 * form beside a React-rendered one would need the same rules written twice, in
 * two languages, with only one of them under test.
 *
 * ## The bootstrap hands down rules, not just credentials
 *
 * The obvious contents are the REST root and a nonce.  This payload also
 * carries the profile catalogue, the stage ids and labels, and the
 * `wp-config.php` warning text — because each of those is a rule the server
 * already owns, and a client that restates one has created a second answer to
 * a question that had exactly one.  The stage list in particular is built from
 * the *registered* pipeline, so a third-party stage added through the
 * `fiction_drafts/stages` filter appears in the progress bar without anyone
 * editing JavaScript.
 *
 * What it deliberately does not carry: any filesystem path, the storage
 * directory's slug, or the table prefix.  The client asks for a job by uuid
 * and a volume by sequence and never learns where anything lives — spec §10.2.
 */
final class AdminPage {

	public const MENU_SLUG = 'fiction-drafts';

	public const SCRIPT_HANDLE = 'fiction-drafts-app';

	public const STYLE_HANDLE = 'fiction-drafts-app';

	/**
	 * The global the bundle reads its bootstrap from.
	 */
	public const BOOTSTRAP_GLOBAL = 'fictionDrafts';

	/**
	 * Where the built bundle lands, relative to the plugin root.
	 */
	private const BUILD_DIR = 'build';

	private string $hookSuffix = '';

	public function __construct(
		private readonly StageRegistry $stages,
		private readonly ProfileCatalogue $profiles,
		private readonly SettingsRepository $settings,
		private readonly string $pluginFile
	) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Register the menu, and remember the screen it created.
	 *
	 * `add_menu_page()` returns false for a user who lacks the capability, so
	 * the stored hook suffix stays empty and enqueue() can never match — the
	 * assets are gated by the same call that gates the menu rather than by a
	 * second capability check that could drift from it.
	 */
	public function addMenu(): void {
		$suffix = add_menu_page(
			__( 'Fiction Drafts', 'fiction-drafts' ),
			__( 'Fiction Drafts', 'fiction-drafts' ),
			AbstractController::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render' ],
			'dashicons-backup',
			81
		);

		// Cast rather than test. Core returns false when the current user lacks
		// the capability, but the stubs static analysis reads declare a plain
		// string return — so an is_string() branch is dead to PHPStan and alive
		// at runtime. Casting is true in both readings: false becomes '', which
		// is already this class's sentinel for "no menu, no assets".
		$this->hookSuffix = (string) $suffix;
	}

	/**
	 * The entire server-rendered page.
	 */
	public function render(): void {
		echo '<div id="fiction-drafts-root" class="fd-root"></div>';
	}

	/**
	 * Load the bundle on this plugin's screen and nowhere else.
	 *
	 * A backup UI enqueued globally would put React and every `@wordpress/*`
	 * package it depends on into every admin page on the site, including ones
	 * belonging to other plugins.
	 */
	public function enqueue( string $hookSuffix ): void {
		if ( '' === $this->hookSuffix || $hookSuffix !== $this->hookSuffix ) {
			return;
		}

		$asset = $this->asset();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( self::BUILD_DIR . '/index.js', $this->pluginFile ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Before, not after: the bundle reads the global at module scope, and
		// an inline script appended after it would run once the app had
		// already tried to boot without one.
		wp_add_inline_script( self::SCRIPT_HANDLE, $this->bootstrapScript(), 'before' );

		wp_set_script_translations( self::SCRIPT_HANDLE, 'fiction-drafts' );

		$stylesheet = $this->pluginDir() . self::BUILD_DIR . '/index.css';

		// `wp-scripts` emits index.css only when the entry imports styles, so
		// its absence is a normal build outcome rather than a broken one.
		if ( is_file( $stylesheet ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				plugins_url( self::BUILD_DIR . '/index.css', $this->pluginFile ),
				[ 'wp-components' ],
				(string) filemtime( $stylesheet )
			);
		}
	}

	/**
	 * Everything the app needs to render its first frame.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrap(): array {
		return [
			'restUrl'   => esc_url_raw( rest_url( AbstractController::NAMESPACE . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'canManage' => current_user_can( AbstractController::CAPABILITY ),
			'pollMs'    => 2000,
			'profiles'  => $this->profiles->all(),
			'stages'    => $this->stageList(),
			'defaults'  => [
				'profile'                           => $this->settings->get()->defaultProfile()->value,
				// Stated here so the client has a name for the field rather
				// than a literal, and stated as false so that the only place
				// this value can come from is a fresh checkbox.  It is not read
				// back from settings, because settings has no such field —
				// spec §6.3 makes the opt-in per job, so there is nowhere
				// durable for a previous choice to survive.
				BackupJob::OPTION_INCLUDE_WP_CONFIG => false,
			],
			'wpConfig'  => [
				'label'   => __( 'Include wp-config.php', 'fiction-drafts' ),
				'warning' => __(
					'This file contains your database password and authentication salts. Anyone who obtains this archive can read them.',
					'fiction-drafts'
				),
			],
			'areas'     => $this->customAreas(),
		];
	}

	/**
	 * The registered pipeline, in order, as ids and labels.
	 *
	 * Built from a representative job because `applicableTo()` is per job — a
	 * database-only backup never enters the file stages.  Full is the profile
	 * that touches every stage, which makes this the complete list the client
	 * may ever need to render.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function stageList(): array {
		$representative = new BackupJob( uuid: '00000000-0000-4000-8000-000000000000', profile: BackupProfile::Full );

		return array_values(
			array_map(
				static fn ( Stage $stage ): array => [
					'id'    => $stage->id(),
					'label' => $stage->label(),
				],
				$this->stages->applicableTo( $representative )
			)
		);
	}

	/**
	 * The per-area opt-ins a Custom job carries.
	 *
	 * Keyed by the option name the REST route expects, so the client sends the
	 * key it was given rather than one it composed.
	 *
	 * @return array<int, array{key: string, label: string}>
	 */
	private function customAreas(): array {
		return [
			[
				'key'   => BackupJob::OPTION_INCLUDE_DATABASE,
				'label' => __( 'Database', 'fiction-drafts' ),
			],
			[
				'key'   => BackupJob::OPTION_INCLUDE_CORE,
				'label' => __( 'Site files (core, plugins, themes)', 'fiction-drafts' ),
			],
			[
				'key'   => BackupJob::OPTION_INCLUDE_UPLOADS,
				'label' => __( 'Uploads', 'fiction-drafts' ),
			],
		];
	}

	private function bootstrapScript(): string {
		$json = wp_json_encode( $this->bootstrap() );

		return sprintf(
			'window.%s = %s;',
			self::BOOTSTRAP_GLOBAL,
			false === $json ? '{}' : $json
		);
	}

	/**
	 * Dependencies and version from the build, never hardcoded.
	 *
	 * `wp-scripts` writes `index.asset.php` next to the bundle with the exact
	 * `@wordpress/*` handles the entry imported and a content hash.  Hardcoding
	 * either one means a rebuilt bundle is served from a stale browser cache,
	 * or is loaded without a package it now needs.
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private function asset(): array {
		$path = $this->pluginDir() . self::BUILD_DIR . '/index.asset.php';

		$asset = is_file( $path ) ? include $path : null;

		$dependencies = ( is_array( $asset ) && isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) )
			? array_values( array_filter( $asset['dependencies'], 'is_string' ) )
			: [];

		$version = ( is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) )
			? $asset['version']
			: (string) ( is_file( $path ) ? filemtime( $path ) : 0 );

		return [
			'dependencies' => $dependencies,
			'version'      => $version,
		];
	}

	private function pluginDir(): string {
		return trailingslashit( dirname( $this->pluginFile ) );
	}
}
