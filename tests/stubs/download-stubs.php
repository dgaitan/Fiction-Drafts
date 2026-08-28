<?php

/**
 * Stubs for the download path.
 *
 * The download endpoint is the one surface in this plugin whose correctness is
 * entirely about things PHP makes untestable — headers, `echo`, `exit`, and a
 * nonce. `ResponseEmitter` solves three of those by being an interface; the
 * fourth is solved here, by a nonce implementation that is real enough to be
 * wrong when it should be.
 *
 * `wp_verify_nonce()` below is not a stub that returns true. A nonce check that
 * always passed would make ISC-522 — "a missing or wrong nonce returns 403" —
 * pass while proving nothing, which is the failure mode this project has
 * already been bitten by twice.
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * A nonce that actually depends on the action and on the current user.
	 *
	 * @return int|false 1 for a valid nonce, false otherwise — core's shape.
	 */
	function wp_verify_nonce( string $nonce, string $action = '-1' ): int|false {
		return wp_create_nonce( $action ) === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['fiction_drafts_test_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'fiction_drafts_test_set_user' ) ) {
	function fiction_drafts_test_set_user( int $userId ): void {
		$GLOBALS['fiction_drafts_test_user_id'] = $userId;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		if ( ! isset( $GLOBALS['fiction_drafts_test_options'][ $option ] ) ) {
			return false;
		}

		unset( $GLOBALS['fiction_drafts_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * @param array<string, mixed> $args Query parameters to add.
	 */
	function add_query_arg( array $args, string $url ): string {
		$separator = str_contains( $url, '?' ) ? '&' : '?';

		return $url . $separator . http_build_query( $args );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * Core's `wp_nonce_url()` ends in `esc_html()`.
	 *
	 * The first version of this stub did not, and that omission hid a real bug
	 * for a whole sprint's worth of unit tests: the download URL came back with
	 * `&amp;` separators, so a browser sent `amp;job` and every download was
	 * refused. A double that is kinder than the thing it doubles proves the code
	 * works against the double.
	 */
	function wp_nonce_url( string $url, string $action = '-1' ): string {
		$separator = str_contains( $url, '?' ) ? '&' : '?';

		return htmlspecialchars( $url . $separator . '_wpnonce=' . wp_create_nonce( $action ), ENT_QUOTES );
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void {
		$GLOBALS['fiction_drafts_test_status'] = $code;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['fiction_drafts_test_nocache'] = true;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $filename ): string {
		$clean = preg_replace( '/[^A-Za-z0-9._-]/', '', $filename );

		return null === $clean ? '' : $clean;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * A recording no-op.
	 *
	 * There is no object cache in a unit test, so there is nothing to
	 * invalidate — but the call has to be observable, because "the read inside
	 * the lock is a fresh read" is a claim about production behaviour that no
	 * assertion about the returned value can make.
	 */
	function wp_cache_delete( string $key, string $group = '' ): bool {
		$GLOBALS['fiction_drafts_test_cache_deletes'][] = $group . ':' . $key;

		return true;
	}
}

if ( ! function_exists( 'fiction_drafts_test_cache_deletes' ) ) {
	/**
	 * @return array<int, string>
	 */
	function fiction_drafts_test_cache_deletes(): array {
		return (array) ( $GLOBALS['fiction_drafts_test_cache_deletes'] ?? [] );
	}
}

if ( ! function_exists( 'fiction_drafts_test_reset_cache_deletes' ) ) {
	function fiction_drafts_test_reset_cache_deletes(): void {
		$GLOBALS['fiction_drafts_test_cache_deletes'] = [];
	}
}

if ( ! function_exists( 'headers_sent' ) ) {
	function headers_sent(): bool {
		return false;
	}
}
