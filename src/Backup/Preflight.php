<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Storage\StorageLocator;
use RuntimeException;

/**
 * The checks that run before a byte of archive is written.
 *
 * One failure mode dominates every other in a backup plugin: filling the disk
 * and taking the site down with it.  A backup that refuses to start is an
 * inconvenience; a site that runs out of space mid-write is an outage, and the
 * plugin that caused it was trying to help.
 *
 * ## The margin, and why it is not 1.0
 *
 * The archive is compressed, so the volumes are usually smaller than their
 * sources — but "usually" is doing a lot of work there.  A media library is
 * mostly JPEG and MP4, both already compressed, and deflate on those is close
 * to a copy plus headers.  The 1.2 multiplier assumes no compression at all
 * and adds a fifth on top for the working directory, the dump, and whatever
 * else on the server wanted space during the run.
 *
 * ## The free-space seam
 *
 * `disk_free_space()` is injectable.  Not for tidiness: without it the failing
 * branch is unreachable on any machine with a normal amount of free space, and
 * a test that can only ever pass is worse than no test — it reports coverage
 * that does not exist.
 */
final class Preflight {

	/**
	 * Headroom over the measured requirement.  Sprint 5 acceptance criterion.
	 */
	public const MARGIN = 1.2;

	/** @var callable(string): (float|false) */
	private $freeSpace;

	/**
	 * @param callable(string): (float|false)|null $freeSpace Free-space probe, or null for disk_free_space().
	 */
	public function __construct(
		private readonly StorageLocator $storage,
		?callable $freeSpace = null
	) {
		$this->freeSpace = $freeSpace ?? static fn ( string $path ): float|false => disk_free_space( $path );
	}

	/**
	 * Refuse the job unless the storage root exists and can be written to.
	 *
	 * @throws RuntimeException With a message an administrator can act on.
	 */
	public function assertWritable(): void {
		// Asked before ensure(), not after. ensure() writes the guard files,
		// and a directory that exists but has had its permissions tightened
		// should produce this sentence rather than a filesystem warning from
		// somewhere further down.
		if ( is_dir( $this->storage->baseDir() ) && ! $this->storage->isWritable() ) {
			throw new RuntimeException( $this->notWritable() );
		}

		if ( ! $this->storage->ensure() ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: absolute path to the storage directory. */
					__( 'The backup storage directory could not be created at %s. Check the permissions on wp-content.', 'fiction-drafts' ),
					$this->storage->baseDir()
				)
			);
		}

		if ( ! $this->storage->isWritable() || ! self::canActuallyWrite( $this->storage->baseDir() ) ) {
			throw new RuntimeException( $this->notWritable() );
		}
	}

	/**
	 * Write a byte and take it away again.
	 *
	 * `is_writable()` answers from the mode bits and the effective uid, and is
	 * wrong under `open_basedir`, POSIX ACLs, SELinux, and a read-only mount —
	 * all of which are more common on shared hosting than a plain permission
	 * problem. The only honest question is whether a file can be created here,
	 * so this asks that one.
	 */
	private static function canActuallyWrite( string $directory ): bool {
		$probe = $directory . '/.fiction-drafts-write-test';

		// Suppressed: the answer is the return value. A warning in the site's
		// error log is not what an administrator needs — the sentence this
		// method's caller throws is.
		$written = @file_put_contents( $probe, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $written ) {
			return false;
		}

		wp_delete_file( $probe );

		return true;
	}

	private function notWritable(): string {
		return sprintf(
			/* translators: %s: absolute path to the storage directory. */
			__( 'The backup storage directory at %s is not writable. Check its permissions and its owner.', 'fiction-drafts' ),
			$this->storage->baseDir()
		);
	}

	/**
	 * Refuse the job unless there is room for what it is about to write.
	 *
	 * @param  int $requiredBytes Measured source size — the dump plus the scan.
	 * @throws RuntimeException When the margin is not met.
	 */
	public function assertSpace( int $requiredBytes ): void {
		$needed = (int) ceil( $requiredBytes * self::MARGIN );
		$free   = ( $this->freeSpace )( $this->storage->baseDir() );

		// A host that has disabled disk_free_space() returns false.  Refusing
		// to run because a diagnostic is unavailable would make the plugin
		// unusable on that host; the check is a guard, not the point.
		if ( false === $free ) {
			return;
		}

		if ( (int) $free >= $needed ) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				/* translators: 1: required space, 2: available space. */
				__( 'This backup needs about %1$s of free disk space and only %2$s is available. Free some space or choose a smaller profile, then start it again.', 'fiction-drafts' ),
				self::humanBytes( $needed ),
				self::humanBytes( (int) $free )
			)
		);
	}

	/**
	 * How much room a job needs, measured rather than estimated.
	 *
	 * The dump's size is on disk already.  The file total is the sum of the
	 * sizes the scan recorded, which is why this check belongs after the scan
	 * and not before it — before it, the number would be a guess, and a gate
	 * on a guess is decoration.
	 */
	public function requiredBytes( string $dumpPath, int $scannedBytes ): int {
		$dump = is_file( $dumpPath ) ? filesize( $dumpPath ) : 0;

		return ( false === $dump ? 0 : $dump ) + max( 0, $scannedBytes );
	}

	/**
	 * Bytes as something a person can read in an error message.
	 */
	public static function humanBytes( int $bytes ): string {
		if ( function_exists( 'size_format' ) ) {
			$formatted = size_format( $bytes, 1 );

			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
		}

		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$last  = count( $units ) - 1;
		$value = (float) $bytes;
		$unit  = 0;

		while ( $value >= 1024 && $unit < $last ) {
			$value /= 1024;
			++$unit;
		}

		return sprintf( '%.1f %s', $value, $units[ $unit ] );
	}
}
