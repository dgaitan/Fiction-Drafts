<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Storage\PathGuard;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * The containment predicate, against a real directory tree.
 *
 * Every case here is built on disk rather than asserted about strings, because
 * `realpath()` is the entire mechanism — a test that never creates a symlink
 * cannot know whether symlinks are followed.
 */
final class PathGuardTest extends TestCase {

	private TempTree $tree;

	private string $base;

	protected function setUp(): void {
		$this->tree = new TempTree();
		$this->base = $this->tree->dir( 'storage' );

		$this->tree->file( 'storage/part01.zip', 'archive' );
		$this->tree->file( 'outside.txt', 'secret' );
		$this->tree->dir( 'storage-evil' );
		$this->tree->file( 'storage-evil/part01.zip', 'not ours' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * The control for every rejection below.
	 *
	 * Without it, a guard that returned false for everything — a broken base, a
	 * typo in the fixture — would pass the whole rest of this class.
	 */
	public function testTheLegitimateVolumeIsAccepted(): void {
		$this->assertTrue( PathGuard::within( $this->base, $this->base . '/part01.zip' ) );
	}

	public function testTheBaseItselfIsInsideItself(): void {
		$this->assertTrue( PathGuard::within( $this->base, $this->base ) );
	}

	public function testTraversalIsRejected(): void {
		$this->assertFalse(
			PathGuard::within( $this->base, $this->base . '/../outside.txt' )
		);
	}

	public function testDeepTraversalIsRejected(): void {
		$this->assertFalse(
			PathGuard::within( $this->base, $this->base . '/../../../../../../etc/hosts' )
		);
	}

	/**
	 * The reason `str_starts_with( $path, $base )` is not the check.
	 *
	 * `/tmp/x/storage-evil` starts with `/tmp/x/storage` as a string and is not
	 * inside it as a directory. One character — the separator — is the whole
	 * difference between a containment check and something that looks like one.
	 */
	public function testASiblingWhoseNameStartsWithTheBaseIsRejected(): void {
		$this->assertFalse(
			PathGuard::within( $this->base, $this->tree->path( 'storage-evil/part01.zip' ) )
		);
	}

	public function testAPathThatDoesNotExistIsRejected(): void {
		$this->assertFalse( PathGuard::within( $this->base, $this->base . '/never-written.zip' ) );
	}

	public function testABaseThatDoesNotExistIsRejected(): void {
		$this->assertFalse(
			PathGuard::within( $this->base . '/missing', $this->base . '/part01.zip' )
		);
	}

	public function testASymlinkPointingOutOfTheBaseIsRejected(): void {
		$link = $this->base . '/escape.zip';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filesystem without symlink support raises a warning here, and the branch below is the proper error checking the sniff asks for.
		if ( ! @symlink( $this->tree->path( 'outside.txt' ), $link ) ) {
			$this->markTestSkipped( 'This filesystem does not support symlinks.' );
		}

		// `realpath()` follows the link, so the resolved path is the file
		// outside — which is exactly what containment has to notice.
		$this->assertFalse( PathGuard::within( $this->base, $link ) );
	}

	/**
	 * A symlink that resolves *inside* the base passes containment honestly,
	 * and is still the wrong file to serve: the ledger names the file that was
	 * hashed, and bytes reached through a link are not provably those bytes.
	 */
	public function testAContainedSymlinkPassesContainmentAndFailsTheFileCheck(): void {
		$link = $this->base . '/alias.zip';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filesystem without symlink support raises a warning here, and the branch below is the proper error checking the sniff asks for.
		if ( ! @symlink( $this->base . '/part01.zip', $link ) ) {
			$this->markTestSkipped( 'This filesystem does not support symlinks.' );
		}

		$this->assertTrue( PathGuard::within( $this->base, $link ) );
		$this->assertFalse( PathGuard::isContainedFile( $this->base, $link ) );
	}

	public function testADirectoryIsNotAContainedFile(): void {
		$this->tree->dir( 'storage/working' );

		$this->assertTrue( PathGuard::within( $this->base, $this->base . '/working' ) );
		$this->assertFalse( PathGuard::isContainedFile( $this->base, $this->base . '/working' ) );
	}

	public function testTheLegitimateVolumeIsAContainedFile(): void {
		$this->assertTrue( PathGuard::isContainedFile( $this->base, $this->base . '/part01.zip' ) );
	}

	public function testANestedFileIsContained(): void {
		$this->tree->dir( 'storage/uuid' );
		$this->tree->file( 'storage/uuid/database.sql', 'dump' );

		$this->assertTrue(
			PathGuard::isContainedFile( $this->base, $this->base . '/uuid/database.sql' )
		);
	}
}
