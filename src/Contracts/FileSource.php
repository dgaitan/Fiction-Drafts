<?php

declare( strict_types=1 );

namespace FictionDrafts\Contracts;

use FictionDrafts\Domain\ExclusionSet;

/**
 * Yields the files a backup should contain.
 *
 * Implementations MUST NOT follow symlinks — a symlink pointing at its own
 * parent makes the walk unbounded — and MUST apply $exclusions before
 * yielding, so that an excluded directory is never descended into rather than
 * being walked and then discarded.
 *
 * Returning an iterable (not an array) is deliberate: a site with 100,000
 * files must never have all of its paths in memory at once.
 */
interface FileSource {

	/**
	 * @param  string       $root       Absolute path to walk.
	 * @param  ExclusionSet $exclusions Patterns relative to $root.
	 * @return iterable<int, array{path: string, size: int}> Root-relative path and byte size.
	 */
	public function iterate( string $root, ExclusionSet $exclusions ): iterable;
}
