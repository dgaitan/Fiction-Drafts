<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\ExclusionSet;
use FictionDrafts\Domain\Settings;
use FictionDrafts\Files\ScanScope;
use PHPUnit\Framework\TestCase;

/**
 * What a job's scan covers, and — the harder half — what it starts from.
 */
final class ScanScopeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
	}

	/**
	 * @param array<string, mixed> $options Per-job choices.
	 */
	private function job( BackupProfile $profile, array $options = [] ): BackupJob {
		return new BackupJob( 'uuid-' . $profile->value, $profile, options: $options );
	}

	/** ISC-265 — the whole site, for every profile that includes core */
	public function test_a_core_including_profile_scans_from_the_site_root(): void {
		foreach ( [ BackupProfile::Full, BackupProfile::FilesOnly, BackupProfile::FilesNoMedia ] as $profile ) {
			$this->assertSame( [ '' ], ScanScope::forJob( $this->job( $profile ) )->roots(), $profile->value );
		}
	}

	/** ISC-264 */
	public function test_a_database_only_job_has_no_roots_at_all(): void {
		$this->assertSame( [], ScanScope::forJob( $this->job( BackupProfile::DatabaseOnly ) )->roots() );
	}

	/**
	 * The case that cannot be written as an exclusion list: uploads without core.
	 */
	public function test_a_custom_job_with_uploads_but_not_core_scans_uploads_alone(): void {
		$job = $this->job(
			BackupProfile::Custom,
			[ BackupJob::OPTION_INCLUDE_UPLOADS => true ]
		);

		$this->assertSame( [ 'wp-content/uploads' ], ScanScope::forJob( $job )->roots() );
	}

	public function test_a_custom_job_with_core_scans_the_whole_site_once(): void {
		$job = $this->job(
			BackupProfile::Custom,
			[
				BackupJob::OPTION_INCLUDE_CORE    => true,
				BackupJob::OPTION_INCLUDE_UPLOADS => true,
			]
		);

		// Not [ '', 'wp-content/uploads' ] — walking both would put every
		// media file in the archive twice.
		$this->assertSame( [ '' ], ScanScope::forJob( $job )->roots() );
	}

	/** ISC-266 */
	public function test_files_no_media_excludes_uploads(): void {
		$exclusions = ScanScope::forJob( $this->job( BackupProfile::FilesNoMedia ) )->exclusions();

		$this->assertTrue( $exclusions->matches( 'wp-content/uploads/2024/01/x.jpg' ) );
	}

	/** ISC-267 — the control */
	public function test_full_does_not_exclude_uploads(): void {
		$exclusions = ScanScope::forJob( $this->job( BackupProfile::Full ) )->exclusions();

		$this->assertFalse( $exclusions->matches( 'wp-content/uploads/2024/01/x.jpg' ) );
	}

	public function test_a_custom_job_can_opt_uploads_back_in(): void {
		$job = $this->job(
			BackupProfile::Custom,
			[
				BackupJob::OPTION_INCLUDE_CORE    => true,
				BackupJob::OPTION_INCLUDE_UPLOADS => true,
			]
		);

		$this->assertFalse( ScanScope::forJob( $job )->exclusions()->matches( 'wp-content/uploads/a.jpg' ) );
	}

	/** ISC-268 */
	public function test_wp_config_is_excluded_for_every_profile_by_default(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertTrue(
				ScanScope::forJob( $this->job( $profile ) )->exclusions()->matches( 'wp-config.php' ),
				$profile->value
			);
		}
	}

	/** ISC-269 */
	public function test_the_per_job_opt_in_lifts_the_wp_config_exclusion(): void {
		$job = $this->job( BackupProfile::Full, [ BackupJob::OPTION_INCLUDE_WP_CONFIG => true ] );

		$this->assertFalse( ScanScope::forJob( $job )->exclusions()->matches( 'wp-config.php' ) );
	}

	/** ISC-270 */
	public function test_the_profile_enum_still_knows_nothing_about_wp_config(): void {
		$this->assertFalse( method_exists( BackupProfile::class, 'includesWpConfig' ) );
	}

	/** ISC-271 */
	public function test_administrator_patterns_are_added_to_the_profile_defaults(): void {
		$settings = Settings::defaults()->withExclusions( new ExclusionSet( [ 'wp-content/big/**' ] ) );

		$exclusions = ScanScope::forJob( $this->job( BackupProfile::Full ), $settings )->exclusions();

		$this->assertTrue( $exclusions->matches( 'wp-content/big/x.bin' ) );
		// And the defaults survive rather than being replaced.
		$this->assertTrue( $exclusions->matches( 'wp-content/cache/x.php' ) );
	}

	/** ISC-272 */
	public function test_the_exclusions_filter_adds_patterns_to_every_job(): void {
		fiction_drafts_test_add_filter(
			ScanScope::FILTER_EXCLUSIONS,
			static fn ( array $patterns ): array => [ ...$patterns, 'wp-content/mu-plugins/**' ]
		);

		$exclusions = ScanScope::forJob( $this->job( BackupProfile::Full ) )->exclusions();

		$this->assertTrue( $exclusions->matches( 'wp-content/mu-plugins/x.php' ) );
	}

	/** ISC-273..276 */
	public function test_the_always_excluded_directories_are_all_in_the_set(): void {
		$exclusions = ScanScope::forJob( $this->job( BackupProfile::Full ) )->exclusions();

		$this->assertTrue( $exclusions->matches( 'node_modules/a.js' ), 'node_modules' );
		$this->assertTrue( $exclusions->matches( '.git/config' ), '.git' );
		$this->assertTrue( $exclusions->matches( 'wp-content/cache/o.php' ), 'cache' );
		$this->assertTrue( $exclusions->matches( 'wp-content/upgrade/t.php' ), 'upgrade' );
	}
}
