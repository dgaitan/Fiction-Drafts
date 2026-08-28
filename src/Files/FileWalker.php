<?php

declare( strict_types=1 );

namespace FictionDrafts\Files;

use FictionDrafts\Contracts\FileSource;
use FictionDrafts\Domain\ExclusionSet;
use Generator;

/**
 * Walks one directory at a time, yielding the files a backup should contain.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_dir
 *
 * ## Why this is not RecursiveDirectoryIterator
 *
 * The stage has to stop mid-walk and resume in a later request, and SPL's
 * iterators carry their position in live objects that cannot be serialized.
 * So the traversal is explicit: a caller hands us one directory, we return its
 * immediate contents, and the caller decides what to do with the
 * subdirectories.  FileScanStage keeps that queue on disk, which is what makes
 * a resume O(1) instead of a re-walk.
 *
 * ## Symlinks are skipped, never followed
 *
 * `is_dir()` follows symlinks, so a link pointing at its own parent turns the
 * walk into an unbounded loop.  Every entry is tested with `is_link()` first —
 * the order matters, and it is the whole defence.
 */
final class FileWalker implements FileSource {

	/**
	 * Path segments below the scan root before a directory is refused.
	 */
	public const MAX_DEPTH = 100;

	/**
	 * Absolute paths excluded whatever the patterns say.
	 *
	 * The plugin's own storage directory goes here.  Its name carries 32
	 * random hex characters generated at activation, so no static pattern can
	 * name it, and an archive that contains itself never terminates.
	 *
	 * @var array<int, string>
	 */
	private readonly array $hardExcluded;

	/**
	 * @param array<int, string> $hardExcluded Absolute paths to skip outright.
	 */
	public function __construct( array $hardExcluded = [] ) {
		$this->hardExcluded = array_values(
			array_filter(
				array_map(
					static function ( string $path ): string {
						$real = realpath( $path );

						return false === $real ? '' : $real;
					},
					$hardExcluded
				)
			)
		);
	}

	/**
	 * Every file under $root, depth-first, as a generator.
	 *
	 * Present so that FileWalker satisfies FileSource on its own terms.  The
	 * stage uses children() instead, because it needs to own the queue.
	 *
	 * @param  string       $root       Absolute path to walk.
	 * @param  ExclusionSet $exclusions Patterns relative to $root.
	 * @return Generator<int, array{path: string, size: int}>
	 */
	public function iterate( string $root, ExclusionSet $exclusions ): Generator {
		$queue = [ '' ];

		while ( [] !== $queue ) {
			$relativeDir = array_shift( $queue );
			$listing     = $this->children( $root, $relativeDir, $exclusions );

			foreach ( $listing['files'] as $file ) {
				yield $file;
			}

			// Breadth-first, because FileScanStage's queue lives in an
			// append-only file and cannot be prepended to.  Keeping both
			// traversals identical means a test of one is a test of the other.
			$queue = array_merge( $queue, $listing['dirs'] );
		}
	}

	/**
	 * The immediate contents of one directory, already filtered and sorted.
	 *
	 * @param  string       $root        Absolute scan root.
	 * @param  string       $relativeDir Root-relative directory, '' for the root itself.
	 * @param  ExclusionSet $exclusions  Patterns relative to $root.
	 * @return array{files: array<int, array{path: string, size: int}>, dirs: array<int, string>, skipped: int}
	 */
	public function children( string $root, string $relativeDir, ExclusionSet $exclusions ): array {
		// `is_link()` does not recognise a Windows junction, so a site on IIS
		// can still present a loop.  A depth ceiling bounds it without needing
		// to know how the loop was made.  No real WordPress tree is anywhere
		// near this deep.
		if ( '' !== $relativeDir && substr_count( $relativeDir, '/' ) >= self::MAX_DEPTH ) {
			return [
				'files'   => [],
				'dirs'    => [],
				'skipped' => 0,
			];
		}

		$absolute = '' === $relativeDir
			? rtrim( $root, '/' )
			: rtrim( $root, '/' ) . '/' . $relativeDir;

		$entries = $this->scan( $absolute );

		$files   = [];
		$dirs    = [];
		$skipped = 0;

		foreach ( $entries as $entry ) {
			$relative = '' === $relativeDir ? $entry : $relativeDir . '/' . $entry;
			$path     = $absolute . '/' . $entry;

			// Before anything else.  is_dir() and is_file() both follow
			// symlinks, so a link to a parent directory would otherwise be
			// indistinguishable from a real one.
			if ( is_link( $path ) ) {
				// Not followed, and not silent.  `wp-content/uploads` pointing
				// at a mounted volume is a standard large-site layout, and a
				// backup that quietly omits the whole media library is worse
				// than one that refuses.  Sprint 5's manifest records what this
				// fires; the readme says plainly that links are not followed.
				do_action( 'fiction_drafts/skipped_symlink', $relative, $path );

				// Returned as well as announced.  The action is how a site
				// owner's own code learns about a link; the count is how the
				// manifest does, and a listener registered after the scan
				// started would miss every link seen before it.
				++$skipped;

				continue;
			}

			if ( $this->isHardExcluded( $path ) ) {
				continue;
			}

			if ( $exclusions->matches( $relative ) ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				// Excluded directories are filtered above, so this is only
				// ever reached for a directory we intend to descend into —
				// the prune happens before the descent, not after.
				$dirs[] = $relative;

				continue;
			}

			$size = $this->sizeOf( $path );

			if ( null === $size ) {
				continue;
			}

			$files[] = [
				'path' => $relative,
				'size' => $size,
			];
		}

		return [
			'files'   => $files,
			'dirs'    => $dirs,
			'skipped' => $skipped,
		];
	}

	/**
	 * Sorted directory contents, or an empty list for anything unreadable.
	 *
	 * An unreadable directory is a fact about one site, not a reason to abandon
	 * a backup: a permissions oddity on a single vendor folder should cost that
	 * folder, not the archive.  The sort is what makes two scans of an
	 * unchanged tree produce the same file on the same line.
	 *
	 * @return array<int, string>
	 */
	private function scan( string $absolute ): array {
		if ( ! is_dir( $absolute ) || ! is_readable( $absolute ) ) {
			return [];
		}

		$entries = @scandir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a directory that becomes unreadable mid-walk must cost that directory, not the backup.

		if ( false === $entries ) {
			return [];
		}

		$entries = array_values(
			array_filter(
				$entries,
				static fn ( string $entry ): bool => '.' !== $entry && '..' !== $entry
			)
		);

		sort( $entries, SORT_STRING );

		return $entries;
	}

	/**
	 * A file's size, or null when it is not a readable regular file.
	 *
	 * A file can vanish between scandir() and filesize() — a cache purge, a
	 * cron job, an editor saving over a temp name.  filesize() would emit a
	 * warning and return false, and false cast to int is a zero-byte entry in
	 * files.jsonl that the archive stage then fails to find.
	 */
	private function sizeOf( string $path ): ?int {
		if ( ! is_file( $path ) ) {
			return null;
		}

		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed between the scan and the stat is skipped, not warned about.

		return false === $size ? null : $size;
	}

	private function isHardExcluded( string $path ): bool {
		if ( [] === $this->hardExcluded ) {
			return false;
		}

		$real = realpath( $path );

		if ( false === $real ) {
			return false;
		}

		foreach ( $this->hardExcluded as $excluded ) {
			if ( $real === $excluded || str_starts_with( $real, $excluded . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
