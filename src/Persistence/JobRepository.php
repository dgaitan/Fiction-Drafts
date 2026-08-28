<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Domain\StageCursor;

/**
 * Jobs, in the database.
 *
 * Every query is prepared.  The only interpolated identifier is the table
 * name, which comes from Migrator and never from a request — spec 10.3.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * -- This is the plugin's own table; there is no core API for it, and caching
 * a job row would defeat the point of polling it for progress.
 */
final class JobRepository implements JobStore {

	public function insert( BackupJob $job ): BackupJob {
		global $wpdb;

		$wpdb->insert( Migrator::jobsTable(), $this->toRow( $job ), $this->formats() );

		return $job->with( [ 'id' => (int) $wpdb->insert_id ] );
	}

	public function save( BackupJob $job ): BackupJob {
		global $wpdb;

		if ( null === $job->id ) {
			return $this->insert( $job );
		}

		$wpdb->update(
			Migrator::jobsTable(),
			$this->toRow( $job ),
			[ 'id' => $job->id ],
			$this->formats(),
			[ '%d' ]
		);

		return $job;
	}

	public function findByUuid( string $uuid ): ?BackupJob {
		global $wpdb;

		$table = Migrator::jobsTable();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE uuid = %s LIMIT 1", $uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrator, never from input.
			ARRAY_A
		);

		return is_array( $row ) ? $this->fromRow( $row ) : null;
	}

	public function findActive(): ?BackupJob {
		global $wpdb;

		$table = Migrator::jobsTable();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status IN ( %s, %s ) ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrator, never from input.
				JobStatus::Queued->value,
				JobStatus::Running->value
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->fromRow( $row ) : null;
	}

	/**
	 * @return array<int, BackupJob>
	 */
	public function all( ?JobStatus $status = null, int $limit = 50 ): array {
		global $wpdb;

		$table = Migrator::jobsTable();

		$rows = null === $status
			? $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrator, never from input.
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d", $status->value, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrator, never from input.
				ARRAY_A
			);

		return $this->hydrateAll( $rows );
	}

	/**
	 * @return array<int, BackupJob>
	 */
	public function findStale( string $before ): array {
		global $wpdb;

		$table = Migrator::jobsTable();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = %s AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrator, never from input.
				JobStatus::Running->value,
				$before
			),
			ARRAY_A
		);

		return $this->hydrateAll( $rows );
	}

	public function delete( string $uuid ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Migrator::jobsTable(), [ 'uuid' => $uuid ], [ '%s' ] );
	}

	/**
	 * @param  mixed $rows Raw result set from $wpdb.
	 * @return array<int, BackupJob>
	 */
	private function hydrateAll( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$jobs = [];

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$jobs[] = $this->fromRow( $row );
			}
		}

		return $jobs;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toRow( BackupJob $job ): array {
		return [
			'uuid'         => $job->uuid,
			'profile'      => $job->profile->value,
			'status'       => $job->status->value,
			'stage'        => $job->stage,
			'stage_cursor' => null === $job->cursor ? null : $job->cursor->toJson(),
			'processed'    => $job->processed,
			'total'        => $job->total,
			'size_bytes'   => $job->sizeBytes,
			'options'      => (string) wp_json_encode( $job->options ),
			'error'        => $job->error,
			'created_at'   => $job->createdAt ?? current_time( 'mysql', true ),
			'updated_at'   => $job->updatedAt ?? current_time( 'mysql', true ),
			'completed_at' => $job->completedAt,
		];
	}

	/**
	 * @return array<int, string>
	 */
	private function formats(): array {
		return [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ];
	}

	/**
	 * @param array<string, mixed> $row One row from the jobs table.
	 */
	private function fromRow( array $row ): BackupJob {
		$options = json_decode( (string) ( $row['options'] ?? '' ), true );

		return new BackupJob(
			uuid: (string) ( $row['uuid'] ?? '' ),
			profile: BackupProfile::tryFrom( (string) ( $row['profile'] ?? '' ) ) ?? BackupProfile::Full,
			status: JobStatus::tryFrom( (string) ( $row['status'] ?? '' ) ) ?? JobStatus::Queued,
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			stage: self::nullableString( $row, 'stage' ),
			cursor: StageCursor::fromJson( self::nullableString( $row, 'stage_cursor' ) ),
			processed: (int) ( $row['processed'] ?? 0 ),
			total: (int) ( $row['total'] ?? 0 ),
			sizeBytes: (int) ( $row['size_bytes'] ?? 0 ),
			options: is_array( $options ) ? $options : [],
			error: self::nullableString( $row, 'error' ),
			createdAt: self::nullableString( $row, 'created_at' ),
			updatedAt: self::nullableString( $row, 'updated_at' ),
			completedAt: self::nullableString( $row, 'completed_at' )
		);
	}

	/**
	 * A nullable column as a nullable string.
	 *
	 * @param array<string, mixed> $row One row from the jobs table.
	 */
	private static function nullableString( array $row, string $key ): ?string {
		$value = $row[ $key ] ?? null;

		return null === $value ? null : (string) $value;
	}
}
