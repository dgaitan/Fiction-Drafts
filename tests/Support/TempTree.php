<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

/**
 * A real directory tree in a temp folder, built and torn down per test.
 *
 * Real, not mocked, deliberately.  The three things the walker has to get
 * right — symlink loops, unreadable directories, files that vanish — have no
 * meaning against a virtual filesystem, and a mock that reproduced them would
 * be reproducing my beliefs about the filesystem rather than the filesystem.
 */
final class TempTree {

	public readonly string $root;

	public function __construct( string $prefix = 'fd-tree' ) {
		$this->root = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex( random_bytes( 6 ) );

		mkdir( $this->root, 0777, true );
	}

	/**
	 * Create a file, and every directory above it.
	 */
	public function file( string $relative, string $contents = 'x' ): string {
		$path = $this->root . '/' . $relative;

		$this->dir( dirname( $relative ) );

		file_put_contents( $path, $contents );

		return $path;
	}

	public function dir( string $relative ): string {
		$path = '.' === $relative || '' === $relative
			? $this->root
			: $this->root . '/' . $relative;

		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}

		return $path;
	}

	/**
	 * A symlink at $relative pointing at $target, which may be outside the tree.
	 */
	public function link( string $relative, string $target ): string {
		$path = $this->root . '/' . $relative;

		$this->dir( dirname( $relative ) );

		symlink( $target, $path );

		return $path;
	}

	public function path( string $relative = '' ): string {
		return '' === $relative ? $this->root : $this->root . '/' . $relative;
	}

	public function remove(): void {
		self::removeTree( $this->root );
	}

	public static function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );

			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		chmod( $path, 0777 );

		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! is_string( $entry ) ) {
				continue;
			}

			self::removeTree( $path . '/' . $entry );
		}

		rmdir( $path );
	}
}
