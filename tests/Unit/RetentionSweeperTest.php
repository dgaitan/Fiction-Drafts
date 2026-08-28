<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Domain\BackupProfile as Profile;
use FictionDrafts\Backup\BackupRemover;
use FictionDrafts\Backup\RetentionSweeper;
use FictionDrafts\Backup\Scheduler;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Domain\Settings;
use FictionDrafts\Persistence\SettingsRepository;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\InMemoryVolumeStore;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * The one thing in this plugin that destroys data the administrator asked for.
 *
 * So every test here asserts both halves: what went, and what stayed. A sweep
 * test that only checks the count left behind would pass just as happily if it
 * had deleted the newest three and kept the oldest.
 */
final class RetentionSweeperTest extends TestCase {

	private string $storageDir = '';

	private InMemoryJobStore $jobs;

	private InMemoryVolumeStore $volumes;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->storageDir = sys_get_temp_dir() . '/fd-retention-' . bin2hex( random_bytes( 6 ) );
		$this->jobs       = new InMemoryJobStore();
		$this->volumes    = new InMemoryVolumeStore();

		mkdir( $this->storageDir, 0777, true );
	}

	protected function tearDown(): void {
		TempTree::removeTree( $this->storageDir );

		parent::tearDown();
	}

	private function storage(): StorageLocator {
		return new StorageLocator( $this->storageDir );
	}

	private function naming(): VolumeNaming {
		return new VolumeNaming( $this->storageDir );
	}

	private function sweeper( int $keep = 3 ): RetentionSweeper {
		update_option(
			SettingsRepository::OPTION_NAME,
			Settings::defaults()->withRetentionCount( $keep )->toArray()
		);

		return new RetentionSweeper(
			$this->jobs,
			new BackupRemover( $this->jobs, $this->volumes, $this->storage() ),
			new SettingsRepository()
		);
	}

	/**
	 * A completed backup with one volume on disk, a sidecar, and a ledger row.
	 *
	 * The uuid encodes the ordinal so failures name which backup went.
	 */
	private function backup( int $ordinal, JobStatus $status = JobStatus::Completed ): BackupJob {
		$uuid = sprintf( '%08d-bbbb-cccc-dddd-eeeeeeeeeeee', $ordinal );

		$job = $this->jobs->insert(
			new BackupJob(
				$uuid,
				BackupProfile::Full,
				$status,
				createdAt: sprintf( '2026-08-%02d 12:00:00', $ordinal )
			)
		);

		file_put_contents( $this->naming()->pathFor( $job, 1 ), 'volume ' . $ordinal );
		file_put_contents( $this->naming()->manifestPathFor( $job ), '{}' );

		mkdir( $this->storage()->workingDir( $uuid ), 0777, true );

		$this->volumes->replaceFor(
			$job,
			[ new ArchiveVolume( $uuid, 1, $this->naming()->filenameFor( $job, 1 ), '', 9, str_repeat( 'a', 64 ) ) ]
		);

		return $job;
	}

	private function exists( BackupJob $job ): bool {
		return is_file( $this->naming()->pathFor( $job, 1 ) );
	}

	/** ISC-377, ISC-380 */
	public function test_it_keeps_the_newest_three_and_deletes_the_rest(): void {
		$oldest = $this->backup( 1 );
		$second = $this->backup( 2 );
		$third  = $this->backup( 3 );
		$newest = $this->backup( 4 );

		$this->assertSame( 1, $this->sweeper( 3 )->sweep() );

		$this->assertFalse( $this->exists( $oldest ), 'the oldest backup survived' );
		$this->assertTrue( $this->exists( $second ) );
		$this->assertTrue( $this->exists( $third ) );
		$this->assertTrue( $this->exists( $newest ), 'the newest backup was deleted' );
	}

	/** ISC-380 */
	public function test_exactly_the_keep_count_remains(): void {
		for ( $ordinal = 1; $ordinal <= 6; ++$ordinal ) {
			$this->backup( $ordinal );
		}

		$this->sweeper( 3 )->sweep();

		$this->assertCount( 3, $this->jobs->all( JobStatus::Completed ) );
	}

	/** ISC-378 */
	public function test_it_deletes_the_sidecar_manifest_too(): void {
		$oldest = $this->backup( 1 );
		$this->backup( 2 );

		$this->sweeper( 1 )->sweep();

		$this->assertFileDoesNotExist( $this->naming()->manifestPathFor( $oldest ) );
	}

	/** ISC-379 */
	public function test_it_deletes_the_rows_in_both_tables(): void {
		$oldest = $this->backup( 1 );
		$this->backup( 2 );

		$this->sweeper( 1 )->sweep();

		$this->assertNull( $this->jobs->findByUuid( $oldest->uuid ) );
		$this->assertSame( [], $this->volumes->allFor( $oldest ) );
	}

	/** ISC-379 — a leftover working directory goes with the backup */
	public function test_it_removes_a_working_directory_left_behind(): void {
		$oldest = $this->backup( 1 );
		$this->backup( 2 );

		$this->sweeper( 1 )->sweep();

		$this->assertDirectoryDoesNotExist( $this->storage()->workingDir( $oldest->uuid ) );
	}

	/** ISC-381 */
	public function test_it_never_deletes_a_running_or_queued_job(): void {
		$running = $this->backup( 1, JobStatus::Running );
		$queued  = $this->backup( 2, JobStatus::Queued );
		$done    = $this->backup( 3 );

		$this->sweeper( 1 )->sweep();

		$this->assertTrue( $this->exists( $running ), 'the sweep deleted a running backup' );
		$this->assertTrue( $this->exists( $queued ), 'the sweep deleted a queued backup' );
		$this->assertTrue( $this->exists( $done ) );
	}

	/** ISC-381 — an active job does not count against the keep total either */
	public function test_an_active_job_does_not_push_a_completed_one_out(): void {
		$oldest = $this->backup( 1 );

		$this->backup( 2, JobStatus::Running );

		$this->assertSame( 0, $this->sweeper( 1 )->sweep() );
		$this->assertTrue( $this->exists( $oldest ) );
	}

	/** ISC-382 */
	public function test_zero_means_keep_everything(): void {
		$oldest = $this->backup( 1 );
		$this->backup( 2 );
		$this->backup( 3 );

		$this->assertSame( 0, $this->sweeper( 0 )->sweep() );
		$this->assertTrue( $this->exists( $oldest ) );
		$this->assertCount( 3, $this->jobs->all( JobStatus::Completed ) );
	}

	/** ISC-377 — fewer backups than the keep count is a no-op */
	public function test_it_does_nothing_when_under_the_keep_count(): void {
		$this->backup( 1 );
		$this->backup( 2 );

		$this->assertSame( 0, $this->sweeper( 3 )->sweep() );
	}

	/** ISC-384 — deletion goes through the storage guard */
	public function test_it_leaves_a_file_outside_the_storage_root_alone(): void {
		$outside = sys_get_temp_dir() . '/fd-outside-' . bin2hex( random_bytes( 4 ) );

		file_put_contents( $outside, 'not ours' );

		$this->backup( 1 );
		$this->backup( 2 );

		$this->sweeper( 1 )->sweep();

		$this->assertFileExists( $outside );

		unlink( $outside );
	}

	/**
	 * ISC-383 — registration is checked by firing the hook and observing the
	 * effect, not by reading the hook registry. The registry would say the
	 * callback is attached even if it did nothing.
	 */
	public function test_firing_the_retention_hook_runs_the_sweep(): void {
		$oldest = $this->backup( 1 );
		$this->backup( 2 );

		$this->sweeper( 1 )->register();

		do_action( Scheduler::HOOK_RETENTION );

		$this->assertFalse( $this->exists( $oldest ), 'the retention hook did not reach the sweeper' );
	}

	/** ISC-383 — the control: without register(), firing the hook does nothing */
	public function test_the_hook_does_nothing_when_the_sweeper_is_not_registered(): void {
		$oldest = $this->backup( 1 );
		$this->backup( 2 );

		$this->sweeper( 1 );

		do_action( Scheduler::HOOK_RETENTION );

		$this->assertTrue( $this->exists( $oldest ) );
	}

	/** ISC-377 — deletion announces itself for anyone listening */
	public function test_it_fires_an_action_for_each_backup_it_deletes(): void {
		$this->backup( 1 );
		$this->backup( 2 );

		$this->sweeper( 1 )->sweep();

		$this->assertCount( 1, fiction_drafts_test_did_action( 'fiction_drafts/backup_deleted' ) );
	}
	/**
	 * ISC-384 — a corrupted row cannot become a general-purpose deleter.
	 *
	 * Both halves of a volume filename come out of columns: ten characters of
	 * `created_at`, and eight characters of the uuid with its dashes stripped.
	 * Neither is validated by the schema, and the result is handed to unlink().
	 *
	 * The row below is built so its derived path genuinely resolves to a file
	 * outside the storage root — an earlier version of this test used a crafted
	 * uuid whose path landed on nothing, and so passed without the guard being
	 * present at all.
	 */
	public function test_a_hostile_job_row_cannot_name_a_file_outside_the_storage_root(): void {
		// The path VolumeNaming composes is
		// "{base}/fiction-drafts-{date}-{fragment}-part01.zip". A fragment of
		// "/../../x" climbs out of the storage root and out of its parent,
		// landing on a real file two directories up.
		$canary = dirname( $this->storageDir ) . '/x-part01.zip';

		file_put_contents( $canary, 'not ours' );
		mkdir( $this->storageDir . '/fiction-drafts-2026-08-28-', 0777, true );

		$hostile = new BackupJob(
			'/../../x',
			Profile::Full,
			JobStatus::Completed,
			createdAt: '2026-08-28 00:00:00'
		);

		// The control: without the hex-only fragment rule this path is a real
		// file, so the guard is what stands between the sweep and someone
		// else's data.
		$unguarded = $this->storageDir . '/fiction-drafts-2026-08-28-/../../x-part01.zip';

		$this->assertFileExists( $unguarded, 'the crafted path does not resolve to a file, so this proves nothing' );

		$this->naming()->removeAllFor( $hostile );

		$this->assertFileExists( $canary, 'a crafted job row reached a file outside the storage root' );

		unlink( $canary );
	}

	/**
	 * ISC-384 — a symlink planted in the storage root is refused.
	 *
	 * `unlink()` removes a link rather than following it, so the target was
	 * never at risk. What the guard stops is the sweep reporting that it
	 * removed a volume when what it removed was a link someone left there.
	 */
	public function test_a_symlinked_volume_is_refused_rather_than_followed(): void {
		$canary = sys_get_temp_dir() . '/fd-canary-' . bin2hex( random_bytes( 4 ) ) . '.zip';

		file_put_contents( $canary, 'not ours' );

		$job = new BackupJob(
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			Profile::Full,
			JobStatus::Completed,
			createdAt: '2026-08-28 12:00:00'
		);

		$link = $this->naming()->pathFor( $job, 1 );

		symlink( $canary, $link );

		$this->assertSame( 0, $this->naming()->removeAllFor( $job ) );
		$this->assertFileExists( $canary );
		$this->assertTrue( is_link( $link ), 'the sweep removed something it should have refused' );

		unlink( $link );
		unlink( $canary );
	}

	/**
	 * ISC-382 — every shape "unset" arrives in. `(int)` of a missing or empty
	 * option is also zero, and a keep count that ever meant "keep none" would
	 * delete every backup on the site.
	 */
	public function test_an_absent_or_unparseable_retention_setting_falls_back_to_the_default(): void {
		foreach ( [ null, '', 'lots', [] ] as $stored ) {
			fiction_drafts_test_reset_options();

			if ( null !== $stored ) {
				update_option( SettingsRepository::OPTION_NAME, [ 'retention_count' => $stored ] );
			}

			$this->assertSame(
				Settings::DEFAULT_RETENTION_COUNT,
				( new SettingsRepository() )->get()->retentionCount(),
				'an unset retention count did not fall back to the default'
			);
		}
	}

	/** ISC-382 — the literal zero, which does mean keep everything */
	public function test_a_literal_zero_retention_setting_is_kept_as_zero(): void {
		update_option( SettingsRepository::OPTION_NAME, [ 'retention_count' => '0' ] );

		$this->assertSame( 0, ( new SettingsRepository() )->get()->retentionCount() );
	}

	/**
	 * A failed job's volumes are an archive that stops part-way through, and
	 * nothing resumes one. Until now the sweep only looked at completed
	 * backups, so they accumulated for ever.
	 */
	public function test_it_frees_the_disk_a_failed_job_is_holding(): void {
		$failed = $this->backup( 1, JobStatus::Failed );
		$kept   = $this->backup( 2 );

		$this->sweeper( 5 )->sweep();

		$this->assertFalse( $this->exists( $failed ), 'a failed job kept its partial volumes' );
		$this->assertTrue( $this->exists( $kept ) );
	}

	/** The failed job's row survives, because its error is the only record */
	public function test_a_failed_jobs_row_survives_the_sweep(): void {
		$failed = $this->backup( 1, JobStatus::Failed );

		$this->sweeper( 5 )->sweep();

		$this->assertNotNull( $this->jobs->findByUuid( $failed->uuid ) );
	}
}
