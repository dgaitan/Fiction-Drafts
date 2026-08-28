<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\ExclusionSet;
use PHPUnit\Framework\TestCase;

/**
 * Every row of the spec's section 6.1 table, asserted cell by cell, plus the
 * always-excluded paths of section 6.2.
 */
final class BackupProfileTest extends TestCase {

	/**
	 * The section 6.1 table as data.  If the spec's table changes, this array
	 * changes with it and the enum has to follow.
	 *
	 * @return array<string, array{0: BackupProfile, 1: bool, 2: bool, 3: bool}>
	 */
	public static function profileMatrix(): array {
		return [
			// profile, database, core, uploads.
			'full'           => [ BackupProfile::Full, true, true, true ],
			'database only'  => [ BackupProfile::DatabaseOnly, true, false, false ],
			'files only'     => [ BackupProfile::FilesOnly, false, true, true ],
			'files no media' => [ BackupProfile::FilesNoMedia, true, true, false ],
			'custom'         => [ BackupProfile::Custom, false, false, false ],
		];
	}

	/**
	 * @dataProvider profileMatrix
	 */
	public function testIncludesDatabaseMatchesTheSpecTable(
		BackupProfile $profile,
		bool $database,
		bool $core,
		bool $uploads
	): void {
		$this->assertSame( $database, $profile->includesDatabase() );
	}

	/**
	 * @dataProvider profileMatrix
	 */
	public function testIncludesCoreMatchesTheSpecTable(
		BackupProfile $profile,
		bool $database,
		bool $core,
		bool $uploads
	): void {
		$this->assertSame( $core, $profile->includesCore() );
	}

	/**
	 * @dataProvider profileMatrix
	 */
	public function testIncludesUploadsMatchesTheSpecTable(
		BackupProfile $profile,
		bool $database,
		bool $core,
		bool $uploads
	): void {
		$this->assertSame( $uploads, $profile->includesUploads() );
	}

	public function testCustomIsDefaultDeny(): void {
		$custom = BackupProfile::Custom;

		$this->assertFalse( $custom->includesDatabase() );
		$this->assertFalse( $custom->includesCore() );
		$this->assertFalse( $custom->includesUploads() );
	}

	public function testEveryProfileYieldsAnExclusionSet(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertInstanceOf(
				ExclusionSet::class,
				$profile->defaultExclusions(),
				$profile->slug() . ' must yield an ExclusionSet'
			);
		}
	}

	public function testEveryProfileExcludesWpConfigByDefault(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertTrue(
				$profile->defaultExclusions()->matches( 'wp-config.php' ),
				$profile->slug() . ' must exclude wp-config.php by default'
			);
		}
	}

	public function testWpConfigExclusionCanBeLiftedPerJob(): void {
		$lifted = BackupProfile::Full->defaultExclusions()->without( 'wp-config.php' );

		$this->assertFalse( $lifted->matches( 'wp-config.php' ) );
	}

	/**
	 * Paths that must be excluded no matter which profile is chosen.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function alwaysExcludedPaths(): array {
		return [
			'object cache'            => [ 'wp-content/cache/object/x.php' ],
			'core upgrade temp'       => [ 'wp-content/upgrade/tmp/x.php' ],
			'root node_modules'       => [ 'node_modules/lib/a.js' ],
			'nested node_modules'     => [ 'wp-content/themes/houzez/node_modules/a.js' ],
			'root git'                => [ '.git/config' ],
			'nested git'              => [ 'wp-content/plugins/x/.git/config' ],
			'root svn'                => [ '.svn/entries' ],
			'root debug log'          => [ 'debug.log' ],
			'nested debug log'        => [ 'wp-content/debug.log' ],
			'root ds store'           => [ '.DS_Store' ],
			'nested ds store'         => [ 'wp-content/.DS_Store' ],
			'backwpup output'         => [ 'wp-content/uploads/backwpup-a1b2/x.zip' ],
			'all in one wp migration' => [ 'wp-content/ai1wm-backups/x.wpress' ],
		];
	}

	/**
	 * @dataProvider alwaysExcludedPaths
	 */
	public function testAlwaysExcludedPathsAreExcludedUnderEveryProfile( string $path ): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertTrue(
				$profile->defaultExclusions()->matches( $path ),
				$profile->slug() . ' must exclude ' . $path
			);
		}
	}

	public function testFilesNoMediaExcludesUploads(): void {
		$this->assertTrue(
			BackupProfile::FilesNoMedia->defaultExclusions()->matches( 'wp-content/uploads/2024/01/x.jpg' )
		);
	}

	public function testFullDoesNotExcludeUploads(): void {
		$this->assertFalse(
			BackupProfile::Full->defaultExclusions()->matches( 'wp-content/uploads/2024/01/x.jpg' )
		);
	}

	/**
	 * The uploads rule is derived from includesUploads(), so a profile can
	 * never claim to skip media and then ship it anyway.
	 */
	public function testUploadsExclusionTracksTheUploadsPredicate(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertSame(
				! $profile->includesUploads(),
				$profile->defaultExclusions()->matches( 'wp-content/uploads/2024/01/x.jpg' ),
				$profile->slug() . ' uploads exclusion must mirror includesUploads()'
			);
		}
	}

	public function testUploadsExclusionDoesNotBleedIntoSimilarlyNamedDirectories(): void {
		$this->assertFalse(
			BackupProfile::FilesNoMedia->defaultExclusions()->matches( 'wp-content/uploads-custom/x.jpg' )
		);
	}

	public function testOrdinaryThemeFilesAreNeverExcluded(): void {
		foreach ( BackupProfile::cases() as $profile ) {
			$this->assertFalse(
				$profile->defaultExclusions()->matches( 'wp-content/themes/houzez/style.css' ),
				$profile->slug() . ' must not exclude ordinary theme files'
			);
		}
	}

	public function testSlugMatchesTheEnumValue(): void {
		$this->assertSame( 'files_no_media', BackupProfile::FilesNoMedia->slug() );
	}

	/**
	 * ISC-328 — a storage directory from an earlier install of this plugin.
	 *
	 * The directory the running instance owns is excluded at runtime by
	 * absolute path, because its 32 hex characters are not knowable
	 * statically.  One left behind by a previous install has no runtime rule
	 * watching it, and it is full of old archives.  Found by a live run that
	 * cheerfully archived one.
	 */
	public function test_a_leftover_storage_directory_is_excluded_by_pattern(): void {
		$exclusions = BackupProfile::Full->defaultExclusions();

		$this->assertTrue( $exclusions->matches( 'wp-content/fiction-drafts-a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4/part01.zip' ) );
		$this->assertTrue( $exclusions->matches( 'wp-content/fiction-drafts-deadbeef/manifest.json' ) );
	}

	/**
	 * ISC-328 — and the pattern stops at the directory it names.
	 */
	public function test_the_leftover_pattern_does_not_bleed_into_the_plugin_directory(): void {
		$exclusions = BackupProfile::Full->defaultExclusions();

		$this->assertFalse( $exclusions->matches( 'wp-content/plugins/fiction-drafts/fiction-drafts.php' ) );
		$this->assertFalse( $exclusions->matches( 'wp-content/fiction-drafts.php' ) );
	}
}
