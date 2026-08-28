<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Storage\StorageLocator;
use PHPUnit\Framework\TestCase;

final class StorageLocatorTest extends TestCase {

	private StorageLocator $storage;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_options();

		$this->storage = new StorageLocator();
	}

	protected function tearDown(): void {
		$base = $this->storage->baseDir();

		if ( is_dir( $base ) ) {
			$this->storage->removeDirectory( $base );
		}

		parent::tearDown();
	}

	public function testTheDirectoryNameCarriesThirtyTwoHexCharacters(): void {
		$slug = $this->storage->slug();

		$this->assertSame( 32, strlen( $slug ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $slug );
	}

	public function testTheSlugIsRememberedRatherThanRegenerated(): void {
		$first = $this->storage->slug();

		$this->assertSame( $first, ( new StorageLocator() )->slug(), 'a new slug would orphan every existing archive' );
	}

	public function testTheSlugIsStoredWithoutAutoloading(): void {
		$this->storage->slug();

		$calls = fiction_drafts_test_option_calls();

		$this->assertCount( 1, $calls['add'] );
		$this->assertSame( StorageLocator::OPTION_SLUG, $calls['add'][0]['option'] );
		$this->assertFalse( $calls['add'][0]['autoload'] );
	}

	public function testTheBaseDirectorySitsUnderWpContent(): void {
		$this->assertStringStartsWith( WP_CONTENT_DIR . '/fiction-drafts-', $this->storage->baseDir() );
	}

	public function testEnsureCreatesTheDirectory(): void {
		$this->assertTrue( $this->storage->ensure() );
		$this->assertDirectoryExists( $this->storage->baseDir() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function guardFiles(): array {
		return [
			'index.php'  => [ 'index.php' ],
			'.htaccess'  => [ '.htaccess' ],
			'web.config' => [ 'web.config' ],
		];
	}

	/**
	 * @dataProvider guardFiles
	 */
	public function testEnsureWritesTheGuardFile( string $filename ): void {
		$this->storage->ensure();

		$this->assertFileExists( $this->storage->baseDir() . '/' . $filename );
	}

	public function testTheHtaccessSaysPlainlyThatNginxIgnoresIt(): void {
		$this->storage->ensure();

		$contents = (string) file_get_contents( $this->storage->baseDir() . '/.htaccess' );

		$this->assertStringContainsString( 'Require all denied', $contents );
		$this->assertStringContainsString( 'nginx', $contents );
	}

	public function testWorkingDirectoriesSitInsideTheBase(): void {
		$working = $this->storage->workingDir( '0f8fad5b-d9cb-469f-a165-70867728950e' );

		$this->assertStringStartsWith( $this->storage->baseDir() . '/', $working );
	}

	public function testRemoveDirectoryDeletesATreeItOwns(): void {
		$this->storage->ensure();

		$working = $this->storage->workingDir( 'deadbeef-dead-beef-dead-beefdeadbeef' );
		mkdir( $working . '/nested', 0777, true );
		file_put_contents( $working . '/nested/file.txt', 'x' );

		$this->assertTrue( $this->storage->removeDirectory( $working ) );
		$this->assertDirectoryDoesNotExist( $working );
	}

	/**
	 * The guard that stops this becoming a general-purpose deleter.
	 */
	public function testRemoveDirectoryRefusesAPathOutsideTheStorageRoot(): void {
		$this->storage->ensure();

		$outside = sys_get_temp_dir() . '/fd-not-ours';
		mkdir( $outside, 0777, true );

		$this->assertFalse( $this->storage->removeDirectory( $outside ) );
		$this->assertDirectoryExists( $outside );

		rmdir( $outside );
	}

	public function testRemoveDirectoryRefusesTraversalOutOfTheRoot(): void {
		$this->storage->ensure();

		$this->assertFalse( $this->storage->removeDirectory( $this->storage->baseDir() . '/../..' ) );
	}
}
