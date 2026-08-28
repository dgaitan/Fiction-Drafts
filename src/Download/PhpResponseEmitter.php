<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

/**
 * The real emitter: PHP's own output, and nothing else.
 *
 * Deliberately the thinnest class in the plugin.  Every decision worth making
 * lives in `DownloadHandler`, which is testable; this holds only the statements
 * that cannot be, so that the untestable surface is a page long and obviously
 * correct by reading rather than by proof.
 *
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 * -- set_time_limit() throws on hosts running it under a disable_functions
 *    policy, and a download that dies because it asked for more time is a worse
 *    outcome than one that keeps the host's limit.
 */
final class PhpResponseEmitter implements ResponseEmitter {

	public function clearBuffers(): void {
		while ( ob_get_level() > 0 ) {
			// Return value ignored on purpose: a buffer opened by something
			// that will not let it be closed is not a reason to abandon the
			// download, and the loop below would otherwise never terminate.
			if ( ! ob_end_clean() ) {
				break;
			}
		}
	}

	public function headersSent(): bool {
		return headers_sent();
	}

	public function clientGone(): bool {
		return 0 !== connection_aborted();
	}

	public function status( int $code ): void {
		status_header( $code );
	}

	public function header( string $name, string $value ): void {
		// Header values here come from a database row and from arithmetic. A
		// row is input, and a CR or LF inside one splits the response into two
		// — the client reads the second half as a body it was never sent.
		header( $name . ': ' . str_replace( [ "\r", "\n" ], '', $value ) );
	}

	public function noTimeLimit(): void {
		// Transparent compression rewrites the body and therefore invalidates
		// both `Content-Length` and every byte offset in a `Content-Range`. It is
		// on by default on a good number of shared hosts, where it turns a
		// working range request into a corrupt resume — and it is off on every
		// development machine, so nothing local ever shows it.
		//
		// A `.zip` does not compress anyway; this costs the response nothing.
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- compression rewrites the body, which invalidates Content-Length and every Content-Range offset; a .zip does not compress, so this costs the response nothing.
		}

		// Stop PHP abandoning the request halfway through a large archive, and
		// stop it continuing to read one nobody is listening to.
		ignore_user_abort( false );

		if ( ! function_exists( 'set_time_limit' ) ) {
			return;
		}

		@set_time_limit( 0 );
	}

	public function write( string $bytes ): void {
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary archive data; escaping it would corrupt the file this endpoint exists to deliver.
	}

	public function flush(): void {
		flush();
	}

	public function finish(): void {
		exit;
	}
}
