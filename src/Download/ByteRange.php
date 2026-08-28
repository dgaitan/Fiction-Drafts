<?php

declare( strict_types=1 );

namespace FictionDrafts\Download;

/**
 * One `Range:` header, resolved against a known file size.
 *
 * A backup volume is measured in gigabytes and is downloaded over whatever
 * connection the administrator happens to have.  Without range support a drop
 * at 96% costs the whole file — and worse than costing it, the partial file is
 * *not* a partial backup: a zip's central directory lives at the end, so a
 * truncated archive is not "most of a backup", it is an unreadable file that
 * looks like one.  Resumability is what makes a large archive obtainable at
 * all.
 *
 * ## Why this is its own class
 *
 * Every byte of the arithmetic here is inclusive-endpoint arithmetic, which is
 * the kind that fails by exactly one and never says so.  `bytes=0-1023` is a
 * thousand and twenty-four bytes, not a thousand and twenty-three; `bytes=100-`
 * ends at `size - 1`, not at `size`.  An off-by-one produces a download that
 * completes, reports success, and yields a corrupt archive.  Isolated here it
 * is provable against real slices of a real file with no HTTP, no WordPress,
 * and no streaming involved.
 *
 * ## The three answers
 *
 * A `Range` header has three outcomes and they are not two:
 *
 * - **No range** — absent or unparseable.  RFC 9110 §14.2 is explicit that a
 *   recipient which cannot understand a range header MUST ignore it, so a
 *   malformed header is a full `200`, not a `400`.  Answering `400` would break
 *   a client whose only sin is an unusual unit.
 * - **Satisfiable** — a `206` over the named slice.
 * - **Unsatisfiable** — syntactically valid but starting at or beyond the end.
 *   That is a `416`, and it must carry `Content-Range: bytes * /size` so the
 *   client learns the real length instead of retrying the same wrong offset.
 *
 * Only multi-range requests are deliberately not honoured: answering one means
 * emitting `multipart/byteranges`, and no download client this endpoint exists
 * for asks for one.  A comma in the header is treated as "cannot understand",
 * which by the rule above means a full `200` — correct, just not clever.
 */
final class ByteRange {

	private function __construct(
		public readonly int $start,
		public readonly int $end,
		public readonly int $size
	) {}

	/**
	 * Resolve a header against a size.
	 *
	 * @param  string|null $header Raw `Range` header value, or null when absent.
	 * @param  int         $size   The complete file's length in bytes.
	 * @return self|null           Null means "serve the whole file".
	 */
	public static function parse( ?string $header, int $size ): ?self {
		if ( null === $header || $size <= 0 ) {
			return null;
		}

		$matched = preg_match( '/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $parts );

		if ( 1 !== $matched ) {
			return null;
		}

		$first = $parts[1];
		$last  = $parts[2];

		if ( '' === $first && '' === $last ) {
			return null;
		}

		if ( '' === $first ) {
			// A suffix range: `bytes=-1024` is the LAST 1024 bytes, not the
			// first.  Read as a start offset it serves the beginning of the
			// file for a client asking for the end, which for a resumed
			// download means silently re-sending bytes it already has.
			$length = (int) $last;

			if ( 0 === $length ) {
				return self::unsatisfiable( $size );
			}

			$start = max( 0, $size - $length );

			return new self( $start, $size - 1, $size );
		}

		$start = (int) $first;

		if ( $start >= $size ) {
			return self::unsatisfiable( $size );
		}

		// An absent last-byte-pos means "to the end", and the end of a file of
		// `size` bytes is offset `size - 1`.
		$end = '' === $last ? $size - 1 : (int) $last;

		// A last-byte-pos past the end is clamped rather than refused: RFC 9110
		// §14.1.2 says a range ending beyond the current length is satisfied by
		// what exists, which is what lets a client ask for `bytes=0-` on a file
		// whose size it is guessing at.
		$end = min( $end, $size - 1 );

		if ( $end < $start ) {
			return self::unsatisfiable( $size );
		}

		return new self( $start, $end, $size );
	}

	private static function unsatisfiable( int $size ): self {
		// Encoded as start > end rather than as a separate type: every caller
		// has to branch on satisfiability anyway, and a second class would give
		// them somewhere to forget to.
		return new self( 1, 0, $size );
	}

	public function isSatisfiable(): bool {
		return $this->start <= $this->end && $this->start >= 0 && $this->end < $this->size;
	}

	/**
	 * How many bytes this range covers.  Inclusive on both ends.
	 */
	public function length(): int {
		return $this->isSatisfiable() ? ( $this->end - $this->start + 1 ) : 0;
	}

	/**
	 * The `Content-Range` value for a `206`.
	 */
	public function contentRange(): string {
		return sprintf( 'bytes %d-%d/%d', $this->start, $this->end, $this->size );
	}

	/**
	 * The `Content-Range` value for a `416`, which names only the real length.
	 */
	public function unsatisfiedRange(): string {
		return sprintf( 'bytes */%d', $this->size );
	}
}
