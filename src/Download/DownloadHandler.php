<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Rest\AbstractController;
use FictionDrafts\Storage\PathGuard;
use FictionDrafts\Storage\StorageLocator;

/**
 * The one place an archive leaves this server.
 *
 * `admin-post.php?action=fiction_drafts_download&job={uuid}&volume={n}&token={t}&_wpnonce={n}`
 *
 * ## Why this is not a REST route
 *
 * Everything else the admin screen does goes through `fiction-drafts/v1`, and
 * this deliberately does not.  A REST response is built, filtered through
 * `rest_pre_serve_request`, and handed to a JSON serializer; pushing four
 * gigabytes of binary through that pipeline means fighting every one of those
 * stages to stop them buffering it.  `admin-post.php` gives an authenticated
 * request with no response machinery attached, which is precisely what
 * streaming a file wants.
 *
 * ## The gates, in this order, and the order is the design
 *
 * 1. **Identity and capability.**  Nothing below runs for a request that has no
 *    business here.
 * 2. **Nonce.**  The CSRF control.  A grant alone would let a page on another
 *    origin cause a logged-in administrator's browser to fetch an archive.
 * 3. **The grant.**  Claimed atomically, so a replayed URL loses the race with
 *    itself.  Single-use and five minutes is what makes a link in browser
 *    history, a proxy log, or a screen share stop being a back door.
 * 4. **What the grant authorises.**  Job, volume, and user all compared — the
 *    grant is permission to fetch *one* file, not permission in general.
 * 5. **The ledger.**  The volume is looked up by job uuid and sequence number.
 *    The client never supplies, and never learns, a path.
 * 6. **`PathGuard`.**  The resolved file must be a real file inside the storage
 *    root and not a symlink, even though step 5 already built the name from a
 *    formula.  Belt and braces on the one operation that reads arbitrary bytes
 *    off the disk and puts them on the wire.
 *
 * Every refusal happens before the file is opened.  That is not tidiness: a
 * gate that opens the file first and refuses second is measurably slower for a
 * real volume than for a missing one, and that difference is an oracle telling
 * an attacker which uuids exist.
 *
 * ## Why the archive is never in memory
 *
 * `readfile()` and `file_get_contents()` both put the whole archive in a
 * process with a 128 MB limit; a 2 GB volume is a fatal, and a fatal on this
 * endpoint is a download that fails with a blank page.  The loop below reads 8
 * MiB, writes it, flushes it, and forgets it, so peak memory is a constant
 * regardless of the file's size.
 */
final class DownloadHandler {

	public const ACTION = 'fiction_drafts_download';

	public const NONCE_ACTION = 'fiction_drafts_download';

	/**
	 * 8 MiB per read.
	 *
	 * Large enough that a gigabyte is a few hundred iterations rather than a
	 * few hundred thousand, small enough that peak memory stays a rounding
	 * error against any sane `memory_limit`.
	 */
	public const CHUNK_BYTES = 8 * 1024 * 1024;

	public function __construct(
		private readonly JobStore $jobs,
		private readonly VolumeStore $volumes,
		private readonly StorageLocator $storage,
		private readonly GrantStore $grants,
		private readonly ResponseEmitter $emitter
	) {}

	public function register(): void {
		// Only the logged-in variant. `admin_post_nopriv_` would register the
		// same handler for anonymous requests, and while gate 1 would refuse
		// them, the honest way to say "this endpoint is not for the public" is
		// to not answer the public at all.
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Serve one volume, or refuse.
	 *
	 * `mixed` rather than `?array`, and the reason is WordPress rather than
	 * taste. `do_action( "admin_post_{$action}" )` is called with no arguments,
	 * and core turns that into one argument anyway:
	 *
	 *     if ( empty( $arg ) ) { $arg[] = ''; }   // wp-includes/plugin.php
	 *
	 * So every zero-argument action callback is handed an empty string. A
	 * callback that takes no parameters never notices; this one declared
	 * `?array` and so fataled with a TypeError on the first real click, in the
	 * one place a unit test calling `handle( [...] )` directly could not see.
	 *
	 * The parameter is still injectable — an array is used as given — which is
	 * what keeps the tests free of `$_GET`. Anything else means WordPress
	 * called us, and the request is where it always was.
	 *
	 * @param array<string, mixed>|string|null $query Request parameters;
	 *                                                anything not an array
	 *                                                falls back to `$_GET`.
	 */
	public function handle( mixed $query = null ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked in gate 2 below, against a value read from this same array.
		$query = is_array( $query ) ? $query : $_GET;

		// Gate 1 — identity, then capability. Deliberately two checks: a
		// logged-out request and an under-privileged one are different
		// situations, and refusing both with the same code is what keeps this
		// endpoint from confirming that a given account exists.
		if ( ! is_user_logged_in() || ! self::canDownload() ) {
			$this->refuse( 403, __( 'You do not have permission to download backups.', 'fiction-drafts' ) );

			return;
		}

		// Gate 2 — CSRF.
		$nonce = isset( $query['_wpnonce'] ) && is_string( $query['_wpnonce'] ) ? $query['_wpnonce'] : '';

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), self::NONCE_ACTION ) ) {
			$this->refuse( 403, __( 'This download link is no longer valid. Please try again from the Backups screen.', 'fiction-drafts' ) );

			return;
		}

		$uuid     = isset( $query['job'] ) && is_string( $query['job'] ) ? sanitize_text_field( wp_unslash( $query['job'] ) ) : '';
		$sequence = self::sequenceFrom( $query['volume'] ?? null );
		$token    = isset( $query['token'] ) && is_string( $query['token'] ) ? sanitize_text_field( wp_unslash( $query['token'] ) ) : '';

		// Gate 3 — claim the grant. Atomic, so a replay loses to itself.
		$grant = $this->grants->consume( $token );

		// Gate 4 — and what it authorises. `authorises()` compares the job, the
		// volume, and the user; a grant for one file is not a key to the rest.
		if ( null === $grant || ! $grant->authorises( $uuid, $sequence, get_current_user_id() ) ) {
			$this->refuse( 403, __( 'This download link has already been used or has expired. Please request a new one.', 'fiction-drafts' ) );

			return;
		}

		// Gate 5 — the ledger. A path is never accepted; it is derived.
		$job = $this->jobs->findByUuid( $uuid );

		if ( null === $job || JobStatus::Completed !== $job->status ) {
			$this->refuse( 404, __( 'That backup could not be found.', 'fiction-drafts' ) );

			return;
		}

		$volume = $this->volumeFor( $job, $sequence );

		if ( null === $volume ) {
			$this->refuse( 404, __( 'That volume is not part of this backup.', 'fiction-drafts' ) );

			return;
		}

		$path = VolumeNaming::forStorage( $this->storage )->pathFor( $job, $sequence );

		// Gate 6 — containment.
		if ( ! PathGuard::isContainedFile( $this->storage->baseDir(), $path ) ) {
			$this->refuse( 404, __( 'That volume is no longer on this server.', 'fiction-drafts' ) );

			return;
		}

		$this->stream( $path, $volume->filename, $volume->sha256 );
	}

	/**
	 * A volume sequence, or zero.
	 *
	 * `absint()` is what the rest of this plugin uses for a number off a
	 * request, and it is wrong here: `absint( '-1' )` is `1`, so a client that
	 * asked for volume minus one would be handed volume one. Nothing unsafe
	 * reaches the disk either way — the grant still has to authorise the
	 * sequence, and the file is still found by formula — but silently rewriting
	 * what was asked for into something else is not a refusal, and a download
	 * endpoint should answer the question it was asked or none at all.
	 */
	private static function sequenceFrom( mixed $raw ): int {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 0;
		}

		if ( ! is_string( $raw ) || 1 !== preg_match( '/^[0-9]{1,6}$/', $raw ) ) {
			return 0;
		}

		return (int) $raw;
	}

	/**
	 * The capability, scoped for multisite.
	 *
	 * Spec §10.2 recorded this as a known gap: `manage_options` is granted per
	 * site, so on a network-activated install every subsite administrator holds
	 * it, while the storage directory and the job rows belong to whichever site
	 * is being served.  A subsite administrator could therefore have listed and
	 * downloaded the main site's archives.
	 *
	 * On multisite the gate is `manage_network_options` — network
	 * administrators only.  That is stricter than a per-site install needs and
	 * will refuse a site administrator on a network where the plugin was
	 * activated for one site alone.  It is the right way to be wrong: this
	 * release does not claim multisite support, and the readme says so, and the
	 * failure mode is a refused download rather than a leaked one.
	 */
	public static function canDownload(): bool {
		return AbstractController::hasCapability();
	}

	/**
	 * The ledger row for a sequence, or null.
	 *
	 * Asked of the ledger rather than of the disk so that a sequence number
	 * outside the backup is a `404` decided from a database row, without the
	 * filesystem being touched at all.
	 */
	private function volumeFor( BackupJob $job, int $sequence ): ?ArchiveVolume {
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

	/**
	 * Send the file, whole or in part.
	 */
	private function stream( string $path, string $filename, string $checksum ): void {
		// Headers already gone means every header() below is a no-op and the
		// archive is appended to whatever was sent — a 200 carrying a file that
		// will not open. Refusing is worse for one download and better than a
		// corrupt archive an administrator only discovers when they need it.
		if ( $this->emitter->headersSent() ) {
			$this->refuse( 500, __( 'Something else on this site sent output before the download could start. Check for a plugin printing a notice or a file with trailing whitespace.', 'fiction-drafts' ) );

			return;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem reads whole files into memory, which is the one thing this endpoint must never do.

		if ( false === $handle ) {
			$this->refuse( 500, __( 'That volume could not be opened.', 'fiction-drafts' ) );

			return;
		}

		// The size comes from the handle that is about to be read, not from a
		// separate stat and not from the ledger row. Between measuring a file
		// and opening it, a retention sweep or a second job can replace it —
		// and a Content-Length taken from the old file with bytes from the new
		// one is a truncated download that matches its own headers.
		// `fstat()` is declared as always returning the full struct, so testing
		// for the key is dead code to static analysis. What is worth testing is
		// the value: a stream with no meaningful size reports zero or less, and
		// serving a zero-length archive as a success is worse than refusing.
		$stat = fstat( $handle );
		$size = (int) $stat['size'];

		if ( $size <= 0 ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above.
			$this->refuse( 500, __( 'That volume could not be read.', 'fiction-drafts' ) );

			return;
		}

		// A validator, so a resume can tell whether it is resuming the same
		// file. Without one, a client that stops at 90%, comes back after the
		// backup was deleted and remade, and sends `If-Range` gets bytes from
		// the new archive appended to bytes from the old — one plausible-looking
		// file that is not either backup. The checksum was computed once by
		// FinalizeStage, so this costs nothing.
		$etag  = '"' . ( '' === $checksum ? md5( $filename . '|' . $size ) : substr( $checksum, 0, 32 ) ) . '"';
		$range = self::ifRangeMatches( $etag ) ? ByteRange::parse( self::rangeHeader(), $size ) : null;

		// Buffers go before the first header, not before the first byte: a
		// buffer opened by something else may already hold output, and output
		// already sent is headers already sent.
		$this->emitter->clearBuffers();
		$this->emitter->noTimeLimit();

		if ( null !== $range && ! $range->isSatisfiable() ) {
			// 416 rather than a silent full response, and it names the real
			// length so the client stops retrying an offset that cannot exist.
			$this->emitter->status( 416 );
			$this->emitter->header( 'Content-Range', $range->unsatisfiedRange() );
			$this->emitter->header( 'Accept-Ranges', 'bytes' );
			$this->emitter->header( 'ETag', $etag );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above.
			$this->emitter->finish();

			return;
		}

		$start  = null === $range ? 0 : $range->start;
		$length = null === $range ? $size : $range->length();

		$this->emitter->status( null === $range ? 200 : 206 );
		$this->sendHeaders( $filename, $length, $etag );

		if ( null !== $range ) {
			$this->emitter->header( 'Content-Range', $range->contentRange() );
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$this->pump( $handle, $length );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above.

		$this->emitter->finish();
	}

	private function sendHeaders( string $filename, int $length, string $etag ): void {
		nocache_headers();

		// nginx buffers a FastCGI response to disk by default, so `flush()`
		// reaches nginx and stops there: the whole archive is spooled into
		// `fastcgi_temp` before a single byte reaches the client, and a
		// multi-gigabyte backup can fill that partition. Harmless on Apache and
		// invisible on any development machine serving PHP directly.
		$this->emitter->header( 'X-Accel-Buffering', 'no' );
		$this->emitter->header( 'ETag', $etag );

		$this->emitter->header( 'Content-Type', 'application/zip' );
		// The filename comes from a database row, and a row is input. Anything
		// but the safe characters is stripped before it reaches a header whose
		// value is quoted.
		$this->emitter->header( 'Content-Disposition', 'attachment; filename="' . self::safeName( $filename ) . '"' );
		// The length of what is being sent — the slice for a 206, not the file.
		// A Content-Length of the whole file on a partial response is how a
		// client ends up waiting for bytes that are never coming.
		$this->emitter->header( 'Content-Length', (string) $length );
		$this->emitter->header( 'X-Content-Type-Options', 'nosniff' );
		$this->emitter->header( 'Accept-Ranges', 'bytes' );
	}

	/**
	 * The read/write/flush loop.
	 *
	 * `$remaining` is counted down rather than reading to EOF, because a 206
	 * must stop at the end of its range and not at the end of the file. The
	 * final read is deliberately shortened to what is left, so the last chunk
	 * of a partial response does not overshoot by up to 8 MiB.
	 *
	 * @param resource $handle Open, positioned file handle.
	 */
	private function pump( $handle, int $length ): void {
		$remaining = $length;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			// Stop reading a file nobody is receiving. Without this a cancelled
			// download holds a PHP worker until the whole archive has been read,
			// and a few cancelled multi-gigabyte downloads exhaust the pool.
			if ( $this->emitter->clientGone() ) {
				return;
			}

			$read  = min( self::CHUNK_BYTES, $remaining );
			$chunk = fread( $handle, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no streaming read; reading the file whole is the one thing this loop exists to avoid.

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$this->emitter->write( $chunk );
			$this->emitter->flush();

			$remaining -= strlen( $chunk );
		}
	}

	/**
	 * Does the client's `If-Range` still match this file?
	 *
	 * RFC 9110 §13.1.5: when it does not, the range is ignored and the whole
	 * file is sent. A client resuming into an archive that has been replaced
	 * gets a fresh, complete download rather than two halves of two files.
	 * No `If-Range` at all means the client is not making that claim, and the
	 * range stands.
	 */
	private static function ifRangeMatches( string $etag ): bool {
		if ( ! isset( $_SERVER['HTTP_IF_RANGE'] ) || ! is_string( $_SERVER['HTTP_IF_RANGE'] ) ) {
			return true;
		}

		$sent = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_RANGE'] ) ) );

		return '' === $sent || $sent === $etag;
	}

	/**
	 * The raw `Range` header, or null.
	 */
	private static function rangeHeader(): ?string {
		if ( ! isset( $_SERVER['HTTP_RANGE'] ) || ! is_string( $_SERVER['HTTP_RANGE'] ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) );
	}

	/**
	 * A filename safe to quote inside a header.
	 */
	private static function safeName( string $filename ): string {
		$safe = sanitize_file_name( $filename );

		return '' === $safe ? 'fiction-drafts-backup.zip' : $safe;
	}

	/**
	 * Refuse, with a status and a sentence.
	 *
	 * `wp_die()` is not used: it renders a styled admin page, and this endpoint
	 * is reached by a download client as often as by a browser. A short plain
	 * body with the right status is what a client can act on.
	 */
	private function refuse( int $status, string $message ): void {
		$this->emitter->clearBuffers();
		$this->emitter->status( $status );
		$this->emitter->header( 'Content-Type', 'text/plain; charset=utf-8' );
		$this->emitter->write( $message );
		$this->emitter->finish();
	}
}
