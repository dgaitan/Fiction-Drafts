<?php
/**
 * Just enough of WordPress's REST and admin surface to run a controller.
 *
 * ## What these prove, and what they do not
 *
 * A controller tested only against stubs written by the same hand as the
 * controller is a differential over two artifacts that share an author — it
 * cannot see a mistake made consistently in both.  These exist for fast
 * regression feedback on the *branching*: which status code comes back for
 * which state, which fields the payload carries, whether a partial body edits
 * or replaces.
 *
 * The claim that the routes actually register, that the capability gate
 * actually refuses, and that the payload actually serialises is settled
 * elsewhere, by dispatching through the real WP_REST_Server on a real
 * WordPress bootstrap.  Neither half is sufficient; the pair is.
 *
 * Behaviours reproduced deliberately, because a controller depends on them:
 *   - get_param() returns null for a parameter that was not sent, *after*
 *     defaults are applied — that is what makes "absent means leave it alone"
 *     distinguishable from "sent as null".
 *   - WP_Error carries its status in $data['status'], which is where
 *     rest_ensure_response() and the tests read it from.
 *   - current_user_can() is switchable, so the same request object can be run
 *     as an administrator and as a subscriber.
 *
 * @package FictionDrafts\Tests
 */

declare( strict_types=1 );

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable Squiz.Commenting.FunctionComment.Missing
// phpcs:disable Squiz.Commenting.ClassComment.Missing
// phpcs:disable Squiz.Commenting.VariableComment.Missing
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// Test doubles: the shapes are dictated by WordPress, and documenting each
// accessor would add noise without adding a fact.

$GLOBALS['fiction_drafts_test_capabilities'] = [ 'manage_options' => true ];
$GLOBALS['fiction_drafts_test_routes']       = [];
$GLOBALS['fiction_drafts_test_logged_in']    = true;

if ( ! function_exists( 'fiction_drafts_test_reset_rest' ) ) {
	function fiction_drafts_test_reset_rest(): void {
		$GLOBALS['fiction_drafts_test_capabilities'] = [ 'manage_options' => true ];
		$GLOBALS['fiction_drafts_test_routes']       = [];
		$GLOBALS['fiction_drafts_test_logged_in']    = true;
	}
}

if ( ! function_exists( 'fiction_drafts_test_set_capability' ) ) {
	function fiction_drafts_test_set_capability( string $capability, bool $granted ): void {
		$GLOBALS['fiction_drafts_test_capabilities'][ $capability ] = $granted;
	}
}

if ( ! function_exists( 'fiction_drafts_test_set_logged_in' ) ) {
	function fiction_drafts_test_set_logged_in( bool $loggedIn ): void {
		$GLOBALS['fiction_drafts_test_logged_in'] = $loggedIn;
	}
}

if ( ! function_exists( 'fiction_drafts_test_routes' ) ) {
	function fiction_drafts_test_routes(): array {
		return $GLOBALS['fiction_drafts_test_routes'];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, mixed ...$args ): bool {
		return true === ( $GLOBALS['fiction_drafts_test_capabilities'][ $capability ] ?? false );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return true === $GLOBALS['fiction_drafts_test_logged_in'];
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	/**
	 * 401 for no identity, 403 for an insufficient one — as core does it.
	 *
	 * The first version of this stub returned a flat 403, and the live run
	 * against a real WP_REST_Server disagreed: an anonymous request comes back
	 * 401. A stub that answers what the author expected rather than what the
	 * subject does is the thing these stubs are most likely to get wrong, so
	 * this one mirrors core's rule instead.
	 */
	function rest_authorization_required_code(): int {
		return is_user_logged_in() ? 403 : 401;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): bool {
		$GLOBALS['fiction_drafts_test_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];

		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'https://fiction-drafts.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '-1' ): string {
		return 'nonce-' . md5( $action );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( preg_replace( '/[\r\n\t\0\x0B]|<[^>]*>/', '', $value ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $value ): string {
		return trim( preg_replace( '/[\t\0\x0B]|<[^>]*>/', '', $value ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $value ): string {
		return trim( preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~@.\-]/', '', $value ) ?? '' );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $value ): string|false {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false;
	}
}

$GLOBALS['fiction_drafts_test_mail'] = [];

if ( ! function_exists( 'fiction_drafts_test_reset_mail' ) ) {
	function fiction_drafts_test_reset_mail(): void {
		$GLOBALS['fiction_drafts_test_mail']        = [];
		$GLOBALS['fiction_drafts_test_mail_result'] = true;
	}
}

if ( ! function_exists( 'fiction_drafts_test_set_mail_result' ) ) {
	function fiction_drafts_test_set_mail_result( bool $result ): void {
		$GLOBALS['fiction_drafts_test_mail_result'] = $result;
	}
}

if ( ! function_exists( 'fiction_drafts_test_sent_mail' ) ) {
	function fiction_drafts_test_sent_mail(): array {
		return $GLOBALS['fiction_drafts_test_mail'];
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * @param string|array<int, string> $to      One address or several.
	 * @param string|array<int, string> $headers Raw header lines.
	 */
	function wp_mail( string|array $to, string $subject, string $message, string|array $headers = '' ): bool {
		$GLOBALS['fiction_drafts_test_mail'][] = [
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		];

		return true === ( $GLOBALS['fiction_drafts_test_mail_result'] ?? true );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return true;
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'https://fiction-drafts.test/wp-content/plugins/fiction-drafts/' . ltrim( $path, '/' );
	}
}

$GLOBALS['fiction_drafts_test_enqueued'] = [
	'scripts'      => [],
	'styles'       => [],
	'inline'       => [],
	'menus'        => [],
	'translations' => [],
];

if ( ! function_exists( 'fiction_drafts_test_reset_enqueued' ) ) {
	function fiction_drafts_test_reset_enqueued(): void {
		$GLOBALS['fiction_drafts_test_enqueued'] = [
			'scripts'      => [],
			'styles'       => [],
			'inline'       => [],
			'menus'        => [],
			'translations' => [],
		];
	}
}

if ( ! function_exists( 'fiction_drafts_test_enqueued' ) ) {
	function fiction_drafts_test_enqueued(): array {
		return $GLOBALS['fiction_drafts_test_enqueued'];
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page(
		string $page_title,
		string $menu_title,
		string $capability,
		string $menu_slug,
		mixed $callback = '',
		string $icon = '',
		mixed $position = null
	): string|false {
		// Core returns false when the current user lacks the capability, which
		// is what makes the asset enqueue inherit the menu's gate for free.
		if ( ! current_user_can( $capability ) ) {
			return false;
		}

		$GLOBALS['fiction_drafts_test_enqueued']['menus'][] = [
			'slug'       => $menu_slug,
			'capability' => $capability,
			'callback'   => $callback,
		];

		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], mixed $version = false, mixed $args = false ): void {
		$GLOBALS['fiction_drafts_test_enqueued']['scripts'][] = [
			'handle'  => $handle,
			'src'     => $src,
			'deps'    => $deps,
			'version' => $version,
		];
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], mixed $version = false, string $media = 'all' ): void {
		$GLOBALS['fiction_drafts_test_enqueued']['styles'][] = [
			'handle'  => $handle,
			'src'     => $src,
			'version' => $version,
		];
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		$GLOBALS['fiction_drafts_test_enqueued']['inline'][] = [
			'handle'   => $handle,
			'data'     => $data,
			'position' => $position,
		];

		return true;
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
		$GLOBALS['fiction_drafts_test_enqueued']['translations'][] = $handle;

		return true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		public string $code;

		public string $message;

		/** @var array<string, mixed> */
		public array $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = is_array( $data ) ? $data : [];
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}

		public function get_error_status(): int {
			return isset( $this->data['status'] ) ? (int) $this->data['status'] : 500;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		/** @var mixed */
		public mixed $data;

		public int $status;

		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		/** @var array<string, mixed> */
		private array $params;

		/**
		 * @param array<string, mixed> $params Parameters as core would present
		 *                                     them, defaults already applied.
		 */
		public function __construct( array $params = [], private string $method = 'GET' ) {
			$this->params = $params;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		/** @return array<string, mixed> */
		public function get_params(): array {
			return $this->params;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function has_param( string $key ): bool {
			return array_key_exists( $key, $this->params );
		}
	}
}
