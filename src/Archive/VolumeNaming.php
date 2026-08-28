<?php

declare( strict_types=1 );

namespace FictionDrafts\Archive;

use FictionDrafts\Domain\BackupJob;

/**
 * The one place that turns a job and a sequence number into a filename.
 *
 * ArchiveStage writes the volumes; FinalizeStage, the retention sweep, and
 * cancellation all have to find them again after the stage that wrote them is
 * gone.  If each derived the name for itself they would drift, and the ledger
 * would disagree with the disk without anything saying so.
 *
 * ## Why sequence-walking is not globbing
 *
 * Sprint 4 ends with a warning against building a backup's volume list by
 * globbing the storage directory: a job that rolled to `part03`, crashed, and
 * finished at `part02` leaves a stale file, and a glob also picks up other
 * jobs' volumes entirely.  Walking `sequenceFor()` from 1 until the first gap
 * asks *this job* what it produced, using the same formula that produced it.
 * The uuid fragment in the name is what makes one job's volumes unmistakable.
 */
final class VolumeNaming {

	/**
	 * Volumes above this are not looked for.  60,000 entries per volume at
	 * this ceiling is more than any single WordPress install will reach, and
	 * an unbounded loop over a filesystem is how a hung request happens.
	 */
	public const MAX_VOLUMES = 999;

	public function __construct( private readonly string $baseDir ) {}

	public function filenameFor( BackupJob $job, int $sequence ): string {
		return sprintf(
			'fiction-drafts-%s-%s-part%02d.zip',
			self::dateOf( $job ),
			self::fragmentOf( $job ),
			max( 1, $sequence )
		);
	}

	public function pathFor( BackupJob $job, int $sequence ): string {
		return $this->baseDir . '/' . $this->filenameFor( $job, $sequence );
	}

	/**
	 * The volumes this job actually produced, in order.
	 *
	 * Stops at the first missing sequence rather than continuing, because a
	 * gap means the run never reached it and anything beyond belongs to an
	 * abandoned attempt.
	 *
	 * @return array<int, int> Sequence numbers, starting at 1.
	 */
	public function sequencesFor( BackupJob $job ): array {
		$found = [];

		for ( $sequence = 1; $sequence <= self::MAX_VOLUMES; ++$sequence ) {
			if ( ! is_file( $this->pathFor( $job, $sequence ) ) ) {
				return $found;
			}

			$found[] = $sequence;
		}

		return $found;
	}

	/**
	 * The sidecar manifest, which sits beside the volumes rather than inside
	 * them so that a backup can be described without being opened.
	 */
	public function manifestPathFor( BackupJob $job ): string {
		return $this->baseDir . '/' . sprintf(
			'fiction-drafts-%s-%s-manifest.json',
			self::dateOf( $job ),
			self::fragmentOf( $job )
		);
	}

	/**
	 * Remove every volume this job produced, and its sidecar manifest.
	 *
	 * Used by cancellation and by retention.  Returns how many files went, so
	 * a caller can report rather than assume.
	 */
	public function removeAllFor( BackupJob $job ): int {
		$removed = 0;

		foreach ( $this->sequencesFor( $job ) as $sequence ) {
			$removed += $this->removeContained( $this->pathFor( $job, $sequence ) );
		}

		$removed += $this->removeContained( $this->manifestPathFor( $job ) );

		return $removed;
	}

	/**
	 * Delete one file, and only if it is a real file directly inside the
	 * storage root.
	 *
	 * Belt and braces over the sanitising above, because deletion is the one
	 * operation here that cannot be undone: `realpath()` resolves whatever the
	 * name actually points at, the parent must be the storage root itself, and
	 * a symlink is refused outright rather than followed. Together that means a
	 * corrupted row can at worst name a file that does not exist.
	 */
	private function removeContained( string $path ): int {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return 0;
		}

		$base   = realpath( $this->baseDir );
		$target = realpath( $path );

		if ( false === $base || false === $target || dirname( $target ) !== $base ) {
			return 0;
		}

		wp_delete_file( $target );

		return 1;
	}

	/**
	 * The job's creation date, so a directory listing sorts chronologically.
	 */
	private static function dateOf( BackupJob $job ): string {
		$date = null === $job->createdAt ? gmdate( 'Y-m-d' ) : substr( $job->createdAt, 0, 10 );

		// Both halves of the name come out of a database row, and a row is not
		// a trusted input just because this plugin usually writes it. Ten
		// characters of `../../../x` is a valid `created_at` as far as the
		// column is concerned, and these names are handed to unlink().
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : gmdate( 'Y-m-d' );
	}

	/**
	 * Eight hex characters of the uuid — enough that two backups taken on one
	 * day cannot collide, short enough that the filename stays readable.
	 */
	private static function fragmentOf( BackupJob $job ): string {
		$fragment = substr( str_replace( '-', '', strtolower( $job->uuid ) ), 0, 8 );

		// Hex only. Stripping the dashes out of `../../../x` leaves `../../..`,
		// which is a path traversal in a string that reaches unlink().
		return 1 === preg_match( '/^[0-9a-f]{8}$/', $fragment ) ? $fragment : str_repeat( '0', 8 );
	}
}
