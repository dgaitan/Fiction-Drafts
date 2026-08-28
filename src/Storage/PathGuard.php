<?php

declare( strict_types=1 );

namespace FictionDrafts\Storage;

/**
 * The one answer to "is this file allowed to be touched?".
 *
 * Spec §10.3 says the plugin writes only inside its own storage directory and
 * that the guard is used on read as well as write.  This is that guard, pulled
 * out of the three places that had grown their own copy of it — `StorageLocator`
 * for directory removal, `VolumeNaming` for file removal, and now the download
 * handler — so that the containment rule has one definition and one test suite.
 *
 * ## Why `str_starts_with( $path, $base )` is not the check
 *
 * It is the check everyone writes, and it lets `/var/storage-evil/secrets.zip`
 * through a guard whose base is `/var/storage`.  The separator matters: a
 * contained path is either the base itself or begins with the base *plus a
 * slash*.  That single character is the difference between a containment check
 * and a string that looks like one.
 *
 * ## Why both sides are resolved
 *
 * `realpath()` collapses `..`, resolves every symlink in the path, and returns
 * `false` for something that does not exist.  Comparing unresolved strings
 * means `/var/storage/../../etc/passwd` passes a prefix test while pointing at
 * `/etc/passwd`, and a symlink named `part01.zip` inside the storage directory
 * passes every textual test there is while reading whatever it points at.
 *
 * Refusing a path that does not exist is deliberate rather than incidental.
 * The alternative — resolving the parent and appending the basename — is how a
 * guard ends up approving a filename for a file nobody has looked at.  Callers
 * that need to create a new file inside the base ask about the directory.
 */
final class PathGuard {

	/**
	 * Is `$path` the base directory itself, or something inside it?
	 *
	 * Both arguments are resolved before comparison.  A path that does not
	 * exist, a base that does not exist, or anything that resolves outside is
	 * `false` — this predicate never throws and never returns a path, because
	 * a guard that returns something is a guard callers forget to check.
	 */
	public static function within( string $base, string $path ): bool {
		$resolvedBase = realpath( $base );
		$resolvedPath = realpath( $path );

		if ( false === $resolvedBase || false === $resolvedPath ) {
			return false;
		}

		if ( $resolvedPath === $resolvedBase ) {
			return true;
		}

		return str_starts_with( $resolvedPath, $resolvedBase . DIRECTORY_SEPARATOR );
	}

	/**
	 * Is this a regular file, inside the base, that is not a symlink?
	 *
	 * The `is_link()` test is not redundant beside `within()`.  `realpath()`
	 * follows the link, so a symlink inside the storage directory pointing at
	 * another file *inside* the storage directory resolves to a contained path
	 * and passes containment honestly.  For serving a download that is still
	 * the wrong answer: the ledger names the file that was hashed, and a link
	 * standing in for it means the bytes on the wire are not the bytes whose
	 * checksum the manifest published.
	 */
	public static function isContainedFile( string $base, string $path ): bool {
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}

		return self::within( $base, $path );
	}
}
