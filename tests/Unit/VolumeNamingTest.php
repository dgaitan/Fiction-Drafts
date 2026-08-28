<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * The one place a job's volume filenames are derived.
 *
 * The point of this class is that finalize, retention, and cancel all find the
 * same files the archive stage wrote. So the tests that matter are the ones
 * about *finding again*, not about formatting.
 */
final class VolumeNamingTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();

		$this->dir = sys_get_temp_dir() . '/fd-naming-' . bin2hex( random_bytes( 6 ) );

		mkdir( $this->dir, 0777, true );
	}

	protected function tearDown(): void {
		TempTree::removeTree( $this->dir );

		parent::tearDown();
	}

	private function job( string $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ): BackupJob {
		return new BackupJob( $uuid, BackupProfile::Full, createdAt: '2026-08-28 12:00:00' );
	}

	private function naming(): VolumeNaming {
		return new VolumeNaming( $this->dir );
	}

	private function touchVolume( BackupJob $job, int $sequence, string $body = 'z' ): string {
		$path = $this->naming()->pathFor( $job, $sequence );

		file_put_contents( $path, $body );

		return $path;
	}

	/** ISC-359 */
	public function test_the_name_carries_the_job_date_and_uuid_fragment(): void {
		$this->assertSame(
			'fiction-drafts-2026-08-28-aaaaaaaa-part01.zip',
			$this->naming()->filenameFor( $this->job(), 1 )
		);
	}

	/** ISC-359 — zero-padded so a directory listing sorts in volume order */
	public function test_sequence_numbers_are_zero_padded(): void {
		$this->assertStringEndsWith( 'part09.zip', $this->naming()->filenameFor( $this->job(), 9 ) );
		$this->assertStringEndsWith( 'part10.zip', $this->naming()->filenameFor( $this->job(), 10 ) );
	}

	/** ISC-359 */
	public function test_it_walks_sequences_from_one(): void {
		$job = $this->job();

		$this->touchVolume( $job, 1 );
		$this->touchVolume( $job, 2 );
		$this->touchVolume( $job, 3 );

		$this->assertSame( [ 1, 2, 3 ], $this->naming()->sequencesFor( $job ) );
	}

	/**
	 * ISC-359, ISC-360 — the whole reason this is a sequence walk and not a
	 * glob. A gap means the run never got there; anything past it belongs to
	 * an abandoned attempt and must not be counted.
	 */
	public function test_it_stops_at_the_first_gap(): void {
		$job = $this->job();

		$this->touchVolume( $job, 1 );
		$this->touchVolume( $job, 2 );
		$this->touchVolume( $job, 4 );

		$this->assertSame( [ 1, 2 ], $this->naming()->sequencesFor( $job ) );
	}

	/**
	 * ISC-360 — the control that a directory glob would fail. Two jobs' volumes
	 * sit side by side; each must see only its own.
	 */
	public function test_it_never_returns_another_jobs_volumes(): void {
		$mine   = $this->job();
		$theirs = $this->job( 'ffffffff-1111-2222-3333-444444444444' );

		$this->touchVolume( $mine, 1 );
		$this->touchVolume( $theirs, 1 );
		$this->touchVolume( $theirs, 2 );

		$this->assertSame( [ 1 ], $this->naming()->sequencesFor( $mine ) );
		$this->assertSame( [ 1, 2 ], $this->naming()->sequencesFor( $theirs ) );

		// The control: a glob over the directory sees three files, so the
		// assertions above are not passing because the directory is empty.
		$this->assertCount( 3, (array) glob( $this->dir . '/*.zip' ) );
	}

	/** ISC-372 */
	public function test_removing_takes_the_volumes_and_the_sidecar_manifest(): void {
		$job = $this->job();

		$this->touchVolume( $job, 1 );
		$this->touchVolume( $job, 2 );

		file_put_contents( $this->naming()->manifestPathFor( $job ), '{}' );

		$this->assertSame( 3, $this->naming()->removeAllFor( $job ) );
		$this->assertSame( [], $this->naming()->sequencesFor( $job ) );
		$this->assertFileDoesNotExist( $this->naming()->manifestPathFor( $job ) );
	}

	/** ISC-372 — removing one job's volumes leaves another job's alone */
	public function test_removing_leaves_other_jobs_untouched(): void {
		$mine   = $this->job();
		$theirs = $this->job( 'ffffffff-1111-2222-3333-444444444444' );

		$this->touchVolume( $mine, 1 );
		$this->touchVolume( $theirs, 1 );

		$this->naming()->removeAllFor( $mine );

		$this->assertSame( [ 1 ], $this->naming()->sequencesFor( $theirs ) );
	}

	/** ISC-359 — a job with no volumes reports none rather than guessing */
	public function test_a_job_with_no_volumes_reports_an_empty_list(): void {
		$this->assertSame( [], $this->naming()->sequencesFor( $this->job() ) );
		$this->assertSame( 0, $this->naming()->removeAllFor( $this->job() ) );
	}
}
