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
		return $this->allForMany( [ $job ] )[ $job->uuid ] ?? [];
	}

	/**
	 * @param  array<int, BackupJob>                  $jobs Jobs to look up.
	 * @return array<string, array<int, ArchiveVolume>>
	 */
	public function allForMany( array $jobs ): array {
		$uuidById = [];
		$volumes  = [];

		foreach ( $jobs as $job ) {
			$volumes[ $job->uuid ] = [];

			if ( null !== $job->id ) {
				$uuidById[ $job->id ] = $job->uuid;
			}
		}

		if ( [] === $uuidById ) {
			return $volumes;
		}

		global $wpdb;

		$table = Migrator::volumesTable();
		$ids   = array_keys( $uuidById );

		// %d per id rather than an imploded list: the ids are integers from
		// our own rows, but building an IN clause by concatenation is a habit
		// that survives into the one place where they are not.
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the table name comes from Migrator and the %d placeholders are generated from the id count, so the sniff cannot see them in the literal; every value is still bound.
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE job_id IN ({$placeholders}) ORDER BY job_id ASC, sequence ASC", ...$ids ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $volumes;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$uuid = $uuidById[ (int) ( $row['job_id'] ?? 0 ) ] ?? null;

			if ( null === $uuid ) {
				continue;
			}

			$volumes[ $uuid ][] = new ArchiveVolume(
				jobUuid: $uuid,
				sequence: (int) ( $row['sequence'] ?? 0 ),
				filename: (string) ( $row['filename'] ?? '' ),
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
