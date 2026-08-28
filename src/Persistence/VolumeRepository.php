<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;

/**
 * Volumes, in the database.
 *
 * Every query is prepared, and the only interpolated identifier is the table
 * name, which comes from Migrator and never from a request — spec 10.3.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * -- The plugin's own table; there is no core API for it, and a cached volume
 * list would be read straight after being written.
 */
final class VolumeRepository implements VolumeStore {

	/**
	 * @param array<int, ArchiveVolume> $volumes Sealed volumes, in sequence order.
	 */
	public function replaceFor( BackupJob $job, array $volumes ): void {
		if ( null === $job->id ) {
			return;
		}

		global $wpdb;

		$this->deleteFor( $job );

		foreach ( $volumes as $volume ) {
			$wpdb->insert(
				Migrator::volumesTable(),
				[
					'job_id'     => $job->id,
					'sequence'   => $volume->sequence,
					'filename'   => $volume->filename,
					'bytes'      => $volume->bytes,
					'sha256'     => $volume->sha256,
					'created_at' => current_time( 'mysql', true ),
				],
				[ '%d', '%d', '%s', '%d', '%s', '%s' ]
			);
		}
	}

	/**
	 * @return array<int, ArchiveVolume>
	 */
	public function allFor( BackupJob $job ): array {
		if ( null === $job->id ) {
			return [];
		}

		global $wpdb;

		$table = Migrator::volumesTable();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE job_id = %d ORDER BY sequence ASC", $job->id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrator, never from input.
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$volumes = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$filename = (string) ( $row['filename'] ?? '' );

			$volumes[] = new ArchiveVolume(
				jobUuid: $job->uuid,
				sequence: (int) ( $row['sequence'] ?? 0 ),
				filename: $filename,
				// The path is derived, never stored.  A stored absolute path
				// survives a site move and points at nothing; the storage root
				// plus the filename is correct wherever the site now lives.
				path: '',
				bytes: (int) ( $row['bytes'] ?? 0 ),
				sha256: (string) ( $row['sha256'] ?? '' )
			);
		}

		return $volumes;
	}

	public function deleteFor( BackupJob $job ): void {
		if ( null === $job->id ) {
			return;
		}

		global $wpdb;

		$wpdb->delete( Migrator::volumesTable(), [ 'job_id' => $job->id ], [ '%d' ] );
	}
}
