<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Download\ByteRange;
use PHPUnit\Framework\TestCase;

/**
 * The range arithmetic, proved against real slices of a real string.
 *
 * Every assertion about a length or an offset here is checked with `substr()`
 * on a known buffer rather than against another number this suite worked out.
 * Two calculations that agree with each other prove they were written by the
 * same person; a calculation checked against the bytes it claims to describe
 * proves the download will not be corrupt.
 */
final class ByteRangeTest extends TestCase {

	private const SIZE = 10000;

	public function testAnAbsentHeaderMeansTheWholeFile(): void {
		$this->assertNull( ByteRange::parse( null, self::SIZE ) );
	}

	public function testAnEmptyHeaderMeansTheWholeFile(): void {
		$this->assertNull( ByteRange::parse( '', self::SIZE ) );
	}

	/**
	 * RFC 9110 §14.2: a recipient that cannot understand a Range header MUST
	 * ignore it. Answering 400 would break a client whose only sin is an
	 * unusual unit, so every one of these is a full 200.
	 */
	public function testAHeaderThatCannotBeUnderstoodIsIgnored(): void {
		foreach ( [ 'items=0-10', 'bytes', 'bytes=', 'bytes=abc-def', 'bytes=0-10,20-30', 'nonsense' ] as $header ) {
			$this->assertNull(
				ByteRange::parse( $header, self::SIZE ),
				sprintf( '"%s" should be ignored, not refused', $header )
			);
		}
	}

	public function testAnOpenEndedRangeRunsToTheLastByte(): void {
		$range = ByteRange::parse( 'bytes=1000-', self::SIZE );

		$this->assertNotNull( $range );
		$this->assertTrue( $range->isSatisfiable() );
		$this->assertSame( 1000, $range->start );
		// The last byte of a 10,000-byte file is offset 9,999. An end of
		// `size` here is the off-by-one that makes every resumed download ask
		// for one byte that does not exist.
		$this->assertSame( 9999, $range->end );
		$this->assertSame( 9000, $range->length() );
	}

	public function testAClosedRangeIsInclusiveOnBothEnds(): void {
		$range = ByteRange::parse( 'bytes=0-1023', self::SIZE );

		$this->assertNotNull( $range );
		// 0 through 1023 inclusive is 1024 bytes, not 1023.
		$this->assertSame( 1024, $range->length() );
	}

	public function testASuffixRangeIsTheEndOfTheFileNotTheStart(): void {
		$range = ByteRange::parse( 'bytes=-1024', self::SIZE );

		$this->assertNotNull( $range );
		$this->assertSame( self::SIZE - 1024, $range->start );
		$this->assertSame( self::SIZE - 1, $range->end );
		$this->assertSame( 1024, $range->length() );
	}

	public function testASuffixLongerThanTheFileIsTheWholeFile(): void {
		$range = ByteRange::parse( 'bytes=-99999', self::SIZE );

		$this->assertNotNull( $range );
		$this->assertSame( 0, $range->start );
		$this->assertSame( self::SIZE, $range->length() );
	}

	public function testAnEndBeyondTheFileIsClampedRatherThanRefused(): void {
		$range = ByteRange::parse( 'bytes=9000-99999', self::SIZE );

		$this->assertNotNull( $range );
		$this->assertTrue( $range->isSatisfiable() );
		$this->assertSame( self::SIZE - 1, $range->end );
		$this->assertSame( 1000, $range->length() );
	}

	public function testAStartAtOrBeyondTheEndIsUnsatisfiable(): void {
		foreach ( [ self::SIZE, self::SIZE + 1, 999999 ] as $start ) {
			$range = ByteRange::parse( 'bytes=' . $start . '-', self::SIZE );

			$this->assertNotNull( $range );
			$this->assertFalse( $range->isSatisfiable(), 'start ' . $start . ' cannot be satisfiable' );
		}
	}

	public function testAZeroLengthSuffixIsUnsatisfiable(): void {
		$range = ByteRange::parse( 'bytes=-0', self::SIZE );

		$this->assertNotNull( $range );
		$this->assertFalse( $range->isSatisfiable() );
	}

	public function testAnUnsatisfiableRangeHasNoLength(): void {
		$range = ByteRange::parse( 'bytes=99999-', self::SIZE );

		$this->assertNotNull( $range );
		$this->assertSame( 0, $range->length() );
	}

	public function testTheContentRangeHeaderNamesTheSliceAndTheWhole(): void {
		$range = ByteRange::parse( 'bytes=1048576-', 2097152 );

		$this->assertNotNull( $range );
		$this->assertSame( 'bytes 1048576-2097151/2097152', $range->contentRange() );
	}

	public function testAnUnsatisfiedRangeNamesOnlyTheRealLength(): void {
		$range = ByteRange::parse( 'bytes=99999-', self::SIZE );

		$this->assertNotNull( $range );
		// A 416 that did not carry the real length would leave the client
		// retrying the same impossible offset for ever.
		$this->assertSame( 'bytes */10000', $range->unsatisfiedRange() );
	}

	/**
	 * The control for every offset assertion above.
	 *
	 * The range is used to cut a real buffer, and the cut is compared against
	 * the bytes it is supposed to be. If `start` or `end` were off by one this
	 * is the assertion that notices, because nothing here recomputes them.
	 */
	public function testEveryRangeDescribesTheBytesItClaimsTo(): void {
		$buffer = random_bytes( self::SIZE );

		$cases = [
			'bytes=0-1023'    => [ 0, 1024 ],
			'bytes=1000-'     => [ 1000, 9000 ],
			'bytes=-1024'     => [ self::SIZE - 1024, 1024 ],
			'bytes=5000-5000' => [ 5000, 1 ],
			'bytes=9999-'     => [ 9999, 1 ],
		];

		foreach ( $cases as $header => [ $offset, $length ] ) {
			$range = ByteRange::parse( $header, self::SIZE );

			$this->assertNotNull( $range, $header );
			$this->assertTrue( $range->isSatisfiable(), $header );
			$this->assertSame(
				substr( $buffer, $offset, $length ),
				substr( $buffer, $range->start, $range->length() ),
				$header . ' does not describe the bytes it claims to'
			);
		}
	}

	public function testAZeroSizedFileHasNoRanges(): void {
		$this->assertNull( ByteRange::parse( 'bytes=0-', 0 ) );
	}

	public function testWhitespaceAndCaseAreTolerated(): void {
		$range = ByteRange::parse( '  Bytes = 100 - 199 ', self::SIZE );

		$this->assertNotNull( $range );
		$this->assertSame( 100, $range->start );
		$this->assertSame( 100, $range->length() );
	}
}
