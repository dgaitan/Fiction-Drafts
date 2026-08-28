<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Download\ResponseEmitter;

/**
 * The emitter, as something a test can read back.
 *
 * This is the whole reason `ResponseEmitter` is an interface. Every assertion
 * in `DownloadHandlerTest` about a status code, a header value, or a byte of
 * the body reads a property here — none of them are "the code looks right".
 *
 * `finish()` records rather than exits, and the handler is written so that
 * every path returns immediately after calling it. A double that actually
 * exited would take PHPUnit with it; one that silently continued would let a
 * handler with a missing `return` pass while sending two responses.
 */
final class RecordingEmitter implements ResponseEmitter {

	public int $status = 0;

	public bool $buffersCleared = false;

	public bool $timeLimitLifted = false;

	public bool $finished = false;

	public int $flushes = 0;

	/** @var array<string, string> */
	public array $headers = [];

	/** @var array<int, int> Byte length of each write, in order. */
	public array $chunkSizes = [];

	public string $body = '';

	/**
	 * How many statements ran before the first header was sent.
	 *
	 * The buffers must be cleared before anything is emitted: output already
	 * sent is headers already sent, and a `header()` call after that is a PHP
	 * warning rather than a header.
	 */
	public bool $clearedBeforeFirstHeader = false;

	private bool $anythingEmitted = false;

	public function clearBuffers(): void {
		if ( ! $this->anythingEmitted ) {
			$this->clearedBeforeFirstHeader = true;
		}

		$this->buffersCleared = true;
	}

	public function status( int $code ): void {
		$this->anythingEmitted = true;
		$this->status          = $code;
	}

	public function header( string $name, string $value ): void {
		$this->anythingEmitted  = true;
		$this->headers[ $name ] = $value;
	}

	/** Flip to make the double behave like a response that already went out. */
	public bool $alreadySent = false;

	/** Flip to make the double behave like a client that hung up. */
	public bool $aborted = false;

	public function headersSent(): bool {
		return $this->alreadySent;
	}

	public function clientGone(): bool {
		return $this->aborted;
	}

	public function noTimeLimit(): void {
		$this->timeLimitLifted = true;
	}

	public function write( string $bytes ): void {
		$this->anythingEmitted = true;
		$this->chunkSizes[]    = strlen( $bytes );
		$this->body           .= $bytes;
	}

	public function flush(): void {
		++$this->flushes;
	}

	public function finish(): void {
		$this->finished = true;
	}

	public function headerValue( string $name ): ?string {
		return $this->headers[ $name ] ?? null;
	}
}
