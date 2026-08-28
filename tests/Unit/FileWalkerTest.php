<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\ExclusionSet;
use FictionDrafts\Files\FileWalker;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * The walker, tested against a filesystem rather than a description of one.
 *
 * A symlink loop, an unreadable directory, and a file that disappears mid-walk
 * are all facts about a real filesystem.  A double that reproduced them would
 * be reproducing what I believe about `scandir()` and `is_dir()`, which is
 * exactly the belief under test.
 */
final class FileWalkerTest extends TestCase {

	private TempTree $tree;

	protected function setUp(): void {
		parent::setUp();

		$this->tree = new TempTree( 'fd-walker' );
	}

	protected function tearDown(): void {
		$this->tree->remove();

		parent::tearDown();
	}

	/**
	 * @return array<int, string>
	 */
	private function walk( ExclusionSet $exclusions = new ExclusionSet(), FileWalker $walker = null ): array {
		$walker ??= new FileWalker();
		$paths    = [];

		foreach ( $walker->iterate( $this->tree->root, $exclusions ) as $file ) {
			$paths[] = $file['path'];
		}

		return $paths;
	}

	/** ISC-241 */
	public function test_iterate_returns_a_generator_not_an_array(): void {
		$this->tree->file( 'a.txt' );

		$this->assertInstanceOf(
			\Generator::class,
			( new FileWalker() )->iterate( $this->tree->root, new ExclusionSet() )
		);
	}

	/** ISC-242 */
	public function test_paths_are_root_relative_with_no_leading_slash(): void {
		$this->tree->file( 'wp-content/themes/x/style.css' );

		$this->assertSame( [ 'wp-content/themes/x/style.css' ], $this->walk() );
	}

	/** ISC-242 — size is the real byte length */
	public function test_size_is_the_files_byte_length(): void {
		$this->tree->file( 'a.txt', str_repeat( 'a', 137 ) );

		$walker  = new FileWalker();
		$listing = $walker->children( $this->tree->root, '', new ExclusionSet() );

		$this->assertSame( 137, $listing['files'][0]['size'] );
	}

	/** ISC-243 */
	public function test_entries_are_visited_in_a_deterministic_order(): void {
		foreach ( [ 'm.txt', 'a.txt', 'z.txt', 'b/c.txt', 'b/a.txt' ] as $file ) {
			$this->tree->file( $file );
		}

		$first  = $this->walk();
		$second = $this->walk();

		$this->assertSame( $first, $second );
		$this->assertSame( [ 'a.txt', 'm.txt', 'z.txt', 'b/a.txt', 'b/c.txt' ], $first );
	}

	/** ISC-244 */
	public function test_a_symlinked_file_is_skipped(): void {
		$target = $this->tree->file( 'real.txt' );
		$this->tree->link( 'link.txt', $target );

		$this->assertSame( [ 'real.txt' ], $this->walk() );
	}

	/** ISC-245 */
	public function test_a_symlinked_directory_is_not_descended_into(): void {
		$this->tree->file( 'real/inside.txt' );
		$this->tree->link( 'alias', $this->tree->path( 'real' ) );

		$this->assertSame( [ 'real/inside.txt' ], $this->walk() );
	}

	/** ISC-246 — the unbounded case, run for real */
	public function test_a_symlink_to_its_own_parent_does_not_recurse(): void {
		$this->tree->file( 'deep/file.txt' );
		$this->tree->link( 'deep/loop', $this->tree->path( 'deep' ) );

		$paths = $this->walk();

		$this->assertSame( [ 'deep/file.txt' ], $paths );
	}

	/**
	 * ISC-348 — the count the manifest reports.
	 *
	 * Returned rather than only announced: `fiction_drafts/skipped_symlink`
	 * tells a site owner's own code about a link as it happens, but a listener
	 * registered part-way through a multi-step scan would miss every link seen
	 * before it. The count travels with the listing instead.
	 */
	public function test_children_reports_how_many_links_it_passed_over(): void {
		$this->tree->file( 'real.txt' );
		$this->tree->link( 'one.txt', $this->tree->path( 'real.txt' ) );
		$this->tree->link( 'two.txt', $this->tree->path( 'real.txt' ) );

		$listing = ( new FileWalker() )->children( $this->tree->root, '', new ExclusionSet() );

		$this->assertSame( 2, $listing['skipped'] );
		$this->assertCount( 1, $listing['files'] );
	}

	/** ISC-348 — the control: a tree with no links reports none */
	public function test_children_reports_zero_when_there_are_no_links(): void {
		$this->tree->file( 'real.txt' );

		$listing = ( new FileWalker() )->children( $this->tree->root, '', new ExclusionSet() );

		$this->assertSame( 0, $listing['skipped'] );
	}

	/** ISC-247 */
	public function test_an_excluded_directory_is_never_descended_into(): void {
		$this->tree->file( 'node_modules/deep/nested/pkg.js' );
		$this->tree->file( 'keep.txt' );

		$walker  = new FileWalker();
		$listing = $walker->children( $this->tree->root, '', new ExclusionSet( [ 'node_modules/**' ] ) );

		// The proof that it was pruned rather than filtered afterwards: the
		// directory is not in the list of places to descend into at all.
		$this->assertSame( [], $listing['dirs'] );
		$this->assertSame( [ 'keep.txt' ], array_column( $listing['files'], 'path' ) );
	}

	/** ISC-248, ISC-249 */
	public function test_a_hard_excluded_absolute_path_is_skipped(): void {
		$storage = $this->tree->dir( 'wp-content/fiction-drafts-deadbeef' );
		$this->tree->file( 'wp-content/fiction-drafts-deadbeef/part01.zip' );
		$this->tree->file( 'wp-content/themes/x/style.css' );

		$paths = $this->walk( new ExclusionSet(), new FileWalker( [ $storage ] ) );

		$this->assertSame( [ 'wp-content/themes/x/style.css' ], $paths );
	}

	/** ISC-249 — the control: without the hard exclusion the archive would contain itself */
	public function test_without_the_hard_exclusion_the_storage_directory_is_walked(): void {
		$this->tree->file( 'wp-content/fiction-drafts-deadbeef/part01.zip' );

		$this->assertContains( 'wp-content/fiction-drafts-deadbeef/part01.zip', $this->walk() );
	}

	/** ISC-250 */
	public function test_an_unreadable_directory_is_skipped_without_aborting(): void {
		$this->tree->file( 'a.txt' );
		$locked = $this->tree->dir( 'locked' );
		$this->tree->file( 'locked/secret.txt' );
		$this->tree->file( 'z.txt' );

		chmod( $locked, 0000 );

		try {
			$paths = $this->walk();
		} finally {
			chmod( $locked, 0777 );
		}

		$this->assertSame( [ 'a.txt', 'z.txt' ], $paths );
	}

	/**
	 * ISC-251 — anything that is not a readable regular file is skipped.
	 *
	 * The regular file beside it is the control: without one, an empty result
	 * would look exactly like a pass.
	 */
	public function test_an_entry_that_is_not_a_regular_file_is_skipped(): void {
		$this->tree->file( 'kept.txt' );

		// A directory is the portable member of "listed by scandir, rejected
		// by is_file" — the same guard that catches a file removed between the
		// listing and the stat, without needing the POSIX extension.
		$this->tree->dir( 'pipe' );

		$paths = $this->walk();

		$this->assertContains( 'kept.txt', $paths );
		$this->assertNotContains( 'pipe', $paths );
	}

	/** ISC-252 */
	public function test_dot_and_dotdot_are_never_yielded(): void {
		$this->tree->file( 'a.txt' );

		$this->assertSame( [ 'a.txt' ], $this->walk() );
	}

	/** ISC-253 */
	public function test_an_unexcluded_dotfile_is_yielded(): void {
		$this->tree->file( '.htaccess' );

		$this->assertSame( [ '.htaccess' ], $this->walk() );
	}
}
