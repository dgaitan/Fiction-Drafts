<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Backup\Manifest;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * What an archive says about the site it came from.
 *
 * The key-set test compares build()'s output against the KEYS constant in both
 * directions. Asserting only one direction would pass while a field silently
 * disappeared, which is the failure that matters: a manifest is read once,
 * years later, by someone who cannot ask what it used to contain.
 */
final class ManifestTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
		fiction_drafts_test_reset_options();

		$this->dir = sys_get_temp_dir() . '/fd-manifest-' . bin2hex( random_bytes( 6 ) );

		mkdir( $this->dir, 0777, true );
	}

	protected function tearDown(): void {
		TempTree::removeTree( $this->dir );

		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $options Per-job choices.
	 */
	private function job( BackupProfile $profile = BackupProfile::Full, array $options = [] ): BackupJob {
		return new BackupJob(
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			$profile,
			options: $options,
			createdAt: '2026-08-28 12:00:00'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build( ?BackupJob $job = null, int $files = 3, int $bytes = 900, int $links = 0 ): array {
		return ( new Manifest() )->build( $job ?? $this->job(), $files, $bytes, $links );
	}

	/** ISC-340 */
	public function test_the_key_set_is_exactly_the_documented_one(): void {
		$keys = array_keys( $this->build() );

		$this->assertSame( Manifest::KEYS, $keys, 'the manifest gained, lost, or reordered a field' );
	}

	/** ISC-340 — the reverse direction, so a dropped field cannot pass */
	public function test_no_documented_key_is_missing(): void {
		$manifest = $this->build();

		foreach ( Manifest::KEYS as $key ) {
			$this->assertArrayHasKey( $key, $manifest );
		}
	}

	/** ISC-341 */
	public function test_it_records_the_site_wordpress_and_php(): void {
		$manifest = $this->build();

		$this->assertSame( 'https://fiction-drafts.test', $manifest['site_url'] );
		$this->assertSame( '6.9', $manifest['wp_version'] );
		$this->assertSame( PHP_VERSION, $manifest['php_version'] );
		$this->assertFalse( $manifest['multisite'] );
	}

	/** ISC-341 — mysql_version is null rather than a plausible guess */
	public function test_the_mysql_version_is_null_without_a_connection(): void {
		$this->assertNull( $this->build()['mysql_version'] );
	}

	/** ISC-341 */
	public function test_it_reads_the_active_theme_from_the_option(): void {
		update_option( 'stylesheet', 'houzez-child' );

		$this->assertSame( 'houzez-child', $this->build()['active_theme'] );
	}

	/** ISC-342 */
	public function test_it_lists_the_active_plugins(): void {
		update_option( 'active_plugins', [ 'fiction-drafts/fiction-drafts.php', 'woocommerce/woocommerce.php', 7 ] );

		$this->assertSame(
			[ 'fiction-drafts/fiction-drafts.php', 'woocommerce/woocommerce.php' ],
			$this->build()['active_plugins']
		);
	}

	/** ISC-343 */
	public function test_it_records_what_a_preset_profile_resolved_to(): void {
		$manifest = $this->build( $this->job( BackupProfile::DatabaseOnly ) );

		$this->assertSame( 'database_only', $manifest['profile'] );
		$this->assertSame(
			[
				'database' => true,
				'core'     => false,
				'uploads'  => false,
			],
			$manifest['profile_areas']
		);
	}

	/**
	 * ISC-343 — the case the field exists for. "custom" alone says nothing
	 * about what was copied.
	 */
	public function test_it_records_what_a_custom_job_actually_selected(): void {
		$job = $this->job(
			BackupProfile::Custom,
			[
				BackupJob::OPTION_INCLUDE_DATABASE => true,
				BackupJob::OPTION_INCLUDE_UPLOADS  => true,
			]
		);

		$this->assertSame(
			[
				'database' => true,
				'core'     => false,
				'uploads'  => true,
			],
			$this->build( $job )['profile_areas']
		);
	}

	/** ISC-344 */
	public function test_wp_config_is_false_for_a_default_job(): void {
		$this->assertFalse( $this->build()['includes_wp_config'] );
	}

	/** ISC-345 */
	public function test_wp_config_is_true_only_when_the_job_opted_in(): void {
		$job = $this->job( BackupProfile::Full, [ BackupJob::OPTION_INCLUDE_WP_CONFIG => true ] );

		$this->assertTrue( $this->build( $job )['includes_wp_config'] );
	}

	/** ISC-345 — it is a job option, never a profile property */
	public function test_no_profile_can_turn_wp_config_on_by_itself(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertFalse(
				$this->build( $this->job( $profile ) )['includes_wp_config'],
				$profile->value . ' turned on wp-config without the job asking'
			);
		}
	}

	/** ISC-346, ISC-347, ISC-348 */
	public function test_it_carries_the_counts_it_was_given(): void {
		$manifest = $this->build( null, 41, 123456, 2 );

		$this->assertSame( 41, $manifest['file_count'] );
		$this->assertSame( 123456, $manifest['total_bytes'] );
		$this->assertSame( 2, $manifest['skipped_symlinks'] );
	}

	/** ISC-349 — the job's creation time, not the write time */
	public function test_created_at_is_the_jobs_own_timestamp(): void {
		$this->assertSame( '2026-08-28 12:00:00', $this->build()['created_at'] );
	}

	/** ISC-352 — the in-archive copy carries the key, and it is empty */
	public function test_volumes_is_present_and_empty_without_a_ledger(): void {
		$manifest = $this->build();

		$this->assertArrayHasKey( 'volumes', $manifest );
		$this->assertSame( [], $manifest['volumes'] );
	}

	/** ISC-351 */
	public function test_a_ledger_records_sequence_filename_bytes_and_hash(): void {
		$manifest = ( new Manifest() )->build(
			$this->job(),
			3,
			900,
			0,
			[ new ArchiveVolume( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 1, 'part01.zip', '/tmp/part01.zip', 512, str_repeat( 'a', 64 ) ) ]
		);

		$this->assertSame(
			[
				'job_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
				'sequence' => 1,
				'filename' => 'part01.zip',
				'bytes'    => 512,
				'sha256'   => str_repeat( 'a', 64 ),
			],
			$manifest['volumes'][0]
		);
	}

	/** ISC-340 — a written manifest reads back identically */
	public function test_it_round_trips_through_the_filesystem(): void {
		$manifest = $this->build();
		$path     = $this->dir . '/' . Manifest::FILENAME;

		$this->assertTrue( Manifest::write( $path, $manifest ) );
		$this->assertSame( $manifest, Manifest::read( $path ) );
	}

	/** ISC-340 — an absent or unparseable manifest is null, never a fatal */
	public function test_reading_a_missing_or_broken_manifest_returns_null(): void {
		$this->assertNull( Manifest::read( $this->dir . '/nothing.json' ) );

		file_put_contents( $this->dir . '/broken.json', 'not json' );

		$this->assertNull( Manifest::read( $this->dir . '/broken.json' ) );
	}
}
