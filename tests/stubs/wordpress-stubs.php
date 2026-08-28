<?php

/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Unit tests run without a WordPress installation.  Only the handful of core
 * functions the domain layer actually calls are stubbed here; anything
 * needing real WordPress behaviour belongs in an integration test.
 */

declare( strict_types=1 );

/*
 * Unit tests must never write into the real wp-content, so the storage root
 * resolves under the system temp directory instead.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/fiction-drafts-unit-tests/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/fiction-drafts-unit-tests' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

/*
 * A working hook system.
 *
 * The stage pipeline is resolved through `fiction_drafts/stages`, so a stub
 * that simply returned the unfiltered value would make the registry untestable
 * — and the registry is exactly the thing that has to be proved not to be a
 * hard-coded array.
 */
$GLOBALS['fiction_drafts_test_hooks'] = [];

if ( ! function_exists( 'fiction_drafts_test_reset_hooks' ) ) {
	function fiction_drafts_test_reset_hooks(): void {
		$GLOBALS['fiction_drafts_test_hooks'] = [];

		// The recorded actions go too. They did not before, so a test that
		// counted how many times an action fired was counting every earlier
		// test in the class as well — and passed anyway, because it asserted
		// "at least one".
		$GLOBALS['fiction_drafts_test_actions']       = [];
		$GLOBALS['fiction_drafts_test_option_misses'] = [];
	}
}

if ( ! function_exists( 'fiction_drafts_test_add_filter' ) ) {
	function fiction_drafts_test_add_filter( string $hook, callable $callback ): void {
		$GLOBALS['fiction_drafts_test_hooks'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'fiction_drafts_test_did_action' ) ) {
	function fiction_drafts_test_did_action( string $hook ): array {
		return $GLOBALS['fiction_drafts_test_actions'][ $hook ] ?? [];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['fiction_drafts_test_hooks'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): bool {
		fiction_drafts_test_add_filter( $hook, $callback );

		return true;
	}
}

$GLOBALS['fiction_drafts_test_actions'] = [];

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted = 1 ): bool {
		return add_filter( $hook, $callback, $priority, $accepted );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/*
	 * Records the call *and* dispatches it.
	 *
	 * Recording alone was enough until something needed to prove that a
	 * register() call attaches a callback that does the work — a registry that
	 * merely holds a callable says nothing about what firing it does. Both
	 * behaviours are kept, so `fiction_drafts_test_did_action()` still answers
	 * for every test written against the recording-only version.
	 */
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['fiction_drafts_test_actions'][ $hook ][] = $args;

		/*
		 * Core does this, and the double did not:
		 *
		 *     if ( empty( $arg ) ) { $arg[] = ''; }   // wp-includes/plugin.php
		 *
		 * Every zero-argument action callback is therefore handed an empty
		 * string. Dispatching with no arguments at all made this double kinder
		 * than WordPress, and a kinder double certifies itself: `handle()`
		 * declared `?array`, passed every test here, and threw a TypeError the
		 * first time a real click reached it.
		 */
		if ( [] === $args ) {
			$args = [ '' ];
		}

		foreach ( $GLOBALS['fiction_drafts_test_hooks'][ $hook ] ?? [] as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql', bool|int $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( int $min = 0, int $max = 0 ): int {
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return untrailingslashit( $value ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( string $path ): bool {
		return is_writable( $path );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): void {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}

/*
 * An in-memory options table.
 *
 * SettingsRepository's contract is not only "the right value comes back" but
 * "the row is written with autoload off".  A stub that merely stored values
 * could not tell those apart, so every call is recorded with its arguments and
 * the tests assert against the recording.
 *
 * $GLOBALS is the right home for this: the stubs are plain functions, they
 * cannot close over test state, and PHPUnit resets nothing between tests —
 * fiction_drafts_test_reset_options() does that explicitly from setUp().
 */
$GLOBALS['fiction_drafts_test_options']      = [];
$GLOBALS['fiction_drafts_test_option_calls'] = [
	'get'    => 0,
	'add'    => [],
	'update' => [],
];

if ( ! function_exists( 'fiction_drafts_test_reset_options' ) ) {
	function fiction_drafts_test_reset_options(): void {
		$GLOBALS['fiction_drafts_test_multisite']     = false;
		$GLOBALS['fiction_drafts_test_options']       = [];
		$GLOBALS['fiction_drafts_test_option_calls']  = [
			'get'    => 0,
			'add'    => [],
			'update' => [],
		];
		$GLOBALS['fiction_drafts_test_option_misses'] = [];
	}
}

if ( ! function_exists( 'fiction_drafts_test_option_calls' ) ) {
	function fiction_drafts_test_option_calls(): array {
		return $GLOBALS['fiction_drafts_test_option_calls'];
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default_value = false ): mixed {
		++$GLOBALS['fiction_drafts_test_option_calls']['get'];

		/*
		 * A read that misses a row which does exist.
		 *
		 * This is what an object cache does under concurrency: worker A's
		 * `notoptions` entry predates worker B's write, so A is told the option
		 * is absent while the row is there. Without a way to reproduce it, the
		 * lost-update branch it causes is unreachable from a test — and code
		 * that cannot be reached is code that was never checked.
		 */
		if ( ! empty( $GLOBALS['fiction_drafts_test_option_misses'][ $option ] ) ) {
			--$GLOBALS['fiction_drafts_test_option_misses'][ $option ];

			return $default_value;
		}

		return array_key_exists( $option, $GLOBALS['fiction_drafts_test_options'] )
			? $GLOBALS['fiction_drafts_test_options'][ $option ]
			: $default_value;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null ): bool {
		$GLOBALS['fiction_drafts_test_option_calls']['add'][] = [
			'option'   => $option,
			'value'    => $value,
			'autoload' => $autoload,
		];

		// Real add_option() refuses to overwrite an existing row.
		if ( array_key_exists( $option, $GLOBALS['fiction_drafts_test_options'] ) ) {
			return false;
		}

		$GLOBALS['fiction_drafts_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['fiction_drafts_test_option_calls']['update'][] = [
			'option'   => $option,
			'value'    => $value,
			'autoload' => $autoload,
		];

		$GLOBALS['fiction_drafts_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		// Toggleable, because the multisite capability gate is a branch and a
		// branch that cannot be entered is a branch nothing proves.
		return true === ( $GLOBALS['fiction_drafts_test_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url(): string {
		return 'https://fiction-drafts.test';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? '6.9' : '';
	}
}
