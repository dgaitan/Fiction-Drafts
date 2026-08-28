<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

/**
 * Everything the download handler does to the outside world.
 *
 * `header()`, `echo`, `flush()`, `ob_end_clean()` and `exit` are the four or
 * five most untestable constructs in PHP: they write to a place a test cannot
 * read, and the last one takes the test process with it.  A handler that calls
 * them directly cannot be verified at all — which for the one endpoint in this
 * plugin that serves a file containing every password hash on the site is not
 * an acceptable place to end up.  "The headers are probably right" is how a
 * `Content-Length` that is off by one ships.
 *
 * So they are named here as an interface.  Production gets
 * `PhpResponseEmitter`, which is the same handful of statements it would have
 * written inline.  Tests get a recorder, and every header, every status, every
 * byte, and the fact that the buffers were cleared before the first one become
 * assertions instead of hopes.
 *
 * The interface is the seam, not an abstraction: there will never be a second
 * production implementation, and it exists solely so the thing that matters can
 * be observed.
 */
interface ResponseEmitter {

	/**
	 * Discard every output buffer above this handler.
	 *
	 * WordPress, a theme, or another plugin may have started one, and whatever
	 * sits in it would otherwise be prepended to a zip — producing a file that
	 * downloads cleanly and does not open.  It also means the archive is never
	 * accumulated in memory on its way out.
	 */
	public function clearBuffers(): void;

	/**
	 * Has anything already been sent?
	 *
	 * A BOM in another plugin's file, or a notice printed before this handler
	 * runs, sends the response headers early. Every `header()` after that is a
	 * silent no-op, and the archive is appended to whatever was already on the
	 * wire — producing a `200` and a file that will not open. Refusing is the
	 * only honest answer.
	 */
	public function headersSent(): bool;

	public function status( int $code ): void;

	public function header( string $name, string $value ): void;

	/**
	 * Lift the execution time limit for the length of the stream.
	 */
	public function noTimeLimit(): void;

	public function write( string $bytes ): void;

	/**
	 * Has the client gone away?
	 *
	 * A cancelled multi-gigabyte download otherwise holds a PHP worker until the
	 * whole file has been read, and a handful of cancelled downloads exhausts
	 * the pool.
	 */
	public function clientGone(): bool;

	public function flush(): void;

	/**
	 * End the response.
	 *
	 * Called after the last chunk so that nothing — an admin footer, a notice,
	 * a shutdown hook's stray whitespace — is appended to a binary body.
	 */
	public function finish(): void;
}
