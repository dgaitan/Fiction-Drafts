<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\Preflight;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The gate that refuses a backup rather than filling the disk.
 *
 * Every one of these tests injects the free-space probe. Without the seam the
 * failing branch is unreachable on any development machine — this one reports
 * 236 GB free — and a test that can only ever pass reports coverage that does
 * not exist. Each pair below is a refusal *and* the control that shows the same
 * job passes when the number moves.
 */
final class PreflightTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->dir = sys_get_temp_dir() . '/fd-preflight-' . bin2hex( random_bytes( 6 ) );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->dir ) ) {
			chmod( $this->dir, 0755 );
		}

		TempTree::removeTree( $this->dir );

		parent::tearDown();
	}

	private function preflight( ?int $free = null ): Preflight {
		return new Preflight(
			new StorageLocator( $this->dir ),
			null === $free ? null : static fn ( string $path ): float => (float) $free
		);
	}

	/** ISC-361 */
	public function test_it_refuses_when_free_space_is_below_the_margin(): void {
		// 1,000 bytes of content needs 1,200 with the margin; 1,199 is short.
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/free disk space/' );

		$this->preflight( 1199 )->assertSpace( 1000 );
	}

	/**
	 * ISC-363 — the control. One byte more and the same call succeeds, which
	 * is what proves the refusal above came from the margin and not from the
	 * method throwing unconditionally.
	 */
	public function test_it_allows_exactly_the_margin(): void {
		$this->preflight( 1200 )->assertSpace( 1000 );

		$this->addToAssertionCount( 1 );
	}

	/** ISC-361 — the message names both numbers, so it can be acted on */
	public function test_the_refusal_names_what_is_needed_and_what_is_free(): void {
		try {
			$this->preflight( 1024 )->assertSpace( 10485760 );

			$this->fail( 'preflight allowed a backup with no room for it' );
		} catch ( RuntimeException $refusal ) {
			$this->assertMatchesRegularExpression( '/\d/', $refusal->getMessage() );
			$this->assertStringContainsString( 'available', $refusal->getMessage() );
		}
	}

	/**
	 * ISC-361 — a host with disk_free_space() disabled returns false. Refusing
	 * every backup because a diagnostic is unavailable would make the plugin
	 * useless there; the check is a guard, not the product.
	 */
	public function test_it_allows_the_job_when_free_space_cannot_be_measured(): void {
		$preflight = new Preflight(
			new StorageLocator( $this->dir ),
			static fn ( string $path ): false => false
		);

		$preflight->assertSpace( PHP_INT_MAX >> 4 );

		$this->addToAssertionCount( 1 );
	}

	/** ISC-364 */
	public function test_it_refuses_an_unwritable_storage_directory(): void {
		mkdir( $this->dir, 0777, true );
		chmod( $this->dir, 0555 );

		if ( is_writable( $this->dir ) ) {
			// Running as root, where mode bits do not deny anything.
			$this->markTestSkipped( 'this filesystem or user ignores the write bit' );
		}

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/not writable/' );

		$this->preflight()->assertWritable();
	}

	/** ISC-364 — the control: the same directory, writable, passes */
	public function test_a_writable_storage_directory_passes(): void {
		$this->preflight()->assertWritable();

		$this->assertDirectoryExists( $this->dir );
	}

	/** ISC-365 — the requirement is measured from the dump and the scan */
	public function test_required_bytes_sums_the_dump_and_the_scanned_files(): void {
		mkdir( $this->dir, 0777, true );

		$dump = $this->dir . '/database.sql';

		file_put_contents( $dump, str_repeat( 'x', 512 ) );

		$this->assertSame( 512 + 2048, $this->preflight()->requiredBytes( $dump, 2048 ) );
	}

	/** ISC-367 — a database-only job has no scan, and zero is the truth */
	public function test_required_bytes_handles_a_job_that_never_scanned(): void {
		mkdir( $this->dir, 0777, true );

		$dump = $this->dir . '/database.sql';

		file_put_contents( $dump, str_repeat( 'x', 64 ) );

		$this->assertSame( 64, $this->preflight()->requiredBytes( $dump, 0 ) );
	}

	/** ISC-365 — a missing dump contributes nothing rather than failing */
	public function test_required_bytes_treats_a_missing_dump_as_zero(): void {
		$this->assertSame( 700, $this->preflight()->requiredBytes( $this->dir . '/none.sql', 700 ) );
	}

	/** ISC-361 — the margin is the documented 1.2 */
	public function test_the_margin_is_one_point_two(): void {
		$this->assertSame( 1.2, Preflight::MARGIN );
	}

	/** ISC-361 — sizes in the message are human-readable */
	public function test_bytes_are_formatted_for_a_person(): void {
		$this->assertSame( '1.0 KB', Preflight::humanBytes( 1024 ) );
		$this->assertSame( '1.5 GB', Preflight::humanBytes( 1610612736 ) );
	}
}
