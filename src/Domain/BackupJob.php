<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * One user-initiated backup.
 *
 * A job carries everything the runner needs to resume it in a fresh request:
 * which profile, which stage, and the cursor inside that stage.  Per-job
 * choices that are not profile properties — notably whether `wp-config.php`
 * is included — live in $options.
 */
final class BackupJob {

	/**
	 * Job option key for the per-job `wp-config.php` opt-in.
	 *
	 * Excluded by default in every profile, including Full.  See spec 6.3.
	 */
	public const OPTION_INCLUDE_WP_CONFIG = 'include_wp_config';

	/**
	 * Per-area opt-ins, meaningful only for the Custom profile.
	 *
	 * Every column of Custom is opt-in (spec 6.1), so the profile alone cannot
	 * say what a Custom job copies — these keys can.  For the four preset
	 * profiles the profile answers and these are ignored.
	 */
	public const OPTION_INCLUDE_DATABASE = 'include_database';

	public const OPTION_INCLUDE_CORE = 'include_core';

	public const OPTION_INCLUDE_UPLOADS = 'include_uploads';

	/**
	 * Keep transient rows out of the database dump.  Defaults to true.
	 *
	 * Transients are a cache with an expiry already baked into the row; a copy
	 * of a site does not need last week's cached HTTP responses, and on a busy
	 * site they can be the largest thing in `wp_options`.  It is a per-job
	 * option rather than a setting so that an administrator debugging a
	 * transient-related problem can take one copy that keeps them.
	 */
	public const OPTION_EXCLUDE_TRANSIENTS = 'exclude_transients';

	/**
	 * Volume size for this job, overriding the saved setting.
	 *
	 * Spec section 8 puts volume size in the job's options JSON, and it belongs
	 * there: the setting is the administrator's usual preference, while a
	 * particular backup may be destined for a medium with its own limit.  The
	 * saved setting is clamped to a 10 MiB floor because it is a form field
	 * someone can typo; a per-job value is a deliberate instruction and carries
	 * only the floor that keeps the archive from being all headers.
	 */
	public const OPTION_MAX_VOLUME_BYTES = 'max_volume_bytes';

	/**
	 * @param array<string, mixed> $options Per-job choices.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly BackupProfile $profile,
		public readonly JobStatus $status = JobStatus::Queued,
		public readonly ?int $id = null,
		public readonly ?string $stage = null,
		public readonly ?StageCursor $cursor = null,
		public readonly int $processed = 0,
		public readonly int $total = 0,
		public readonly int $sizeBytes = 0,
		public readonly array $options = [],
		public readonly ?string $error = null,
		public readonly ?string $createdAt = null,
		public readonly ?string $updatedAt = null,
		public readonly ?string $completedAt = null
	) {
	}

	public function cursor(): StageCursor {
		return $this->cursor ?? StageCursor::start();
	}

	public function option( string $key, mixed $fallback = null ): mixed {
		return $this->options[ $key ] ?? $fallback;
	}

	/**
	 * Did the administrator opt this job into including `wp-config.php`?
	 *
	 * Defaults to false, and is never inherited from a previous job.
	 */
	public function includesWpConfig(): bool {
		return true === ( $this->options[ self::OPTION_INCLUDE_WP_CONFIG ] ?? false );
	}

	public function isActive(): bool {
		return $this->status->isActive();
	}

	/**
	 * Does this job export the database?
	 *
	 * The profile answers for the four presets.  Custom defers to the job's
	 * own options, which is the whole difference between a profile (what areas
	 * a named preset covers) and a job (what this particular run was asked for).
	 */
	public function includesDatabase(): bool {
		return BackupProfile::Custom === $this->profile
			? true === ( $this->options[ self::OPTION_INCLUDE_DATABASE ] ?? false )
			: $this->profile->includesDatabase();
	}

	public function includesCore(): bool {
		return BackupProfile::Custom === $this->profile
			? true === ( $this->options[ self::OPTION_INCLUDE_CORE ] ?? false )
			: $this->profile->includesCore();
	}

	public function includesUploads(): bool {
		return BackupProfile::Custom === $this->profile
			? true === ( $this->options[ self::OPTION_INCLUDE_UPLOADS ] ?? false )
			: $this->profile->includesUploads();
	}

	/**
	 * Would this job copy anything at all?
	 *
	 * A job that selects nothing would produce an empty archive and report
	 * success — data loss with a clean exit code.  JobManager refuses to
	 * create one; this is the predicate it refuses on.
	 */
	public function selectsAnyContent(): bool {
		return $this->includesDatabase() || $this->includesCore() || $this->includesUploads();
	}

	public function withStatus( JobStatus $status, ?string $error = null ): self {
		return $this->with(
			[
				'status' => $status,
				'error'  => $error ?? $this->error,
			]
		);
	}

	/**
	 * A copy with some fields replaced.
	 *
	 * @param array<string, mixed> $changes Field name to new value.
	 */
	public function with( array $changes ): self {
		return new self(
			$changes['uuid'] ?? $this->uuid,
			$changes['profile'] ?? $this->profile,
			$changes['status'] ?? $this->status,
			array_key_exists( 'id', $changes ) ? $changes['id'] : $this->id,
			array_key_exists( 'stage', $changes ) ? $changes['stage'] : $this->stage,
			array_key_exists( 'cursor', $changes ) ? $changes['cursor'] : $this->cursor,
			$changes['processed'] ?? $this->processed,
			$changes['total'] ?? $this->total,
			$changes['sizeBytes'] ?? $this->sizeBytes,
			$changes['options'] ?? $this->options,
			array_key_exists( 'error', $changes ) ? $changes['error'] : $this->error,
			array_key_exists( 'createdAt', $changes ) ? $changes['createdAt'] : $this->createdAt,
			array_key_exists( 'updatedAt', $changes ) ? $changes['updatedAt'] : $this->updatedAt,
			array_key_exists( 'completedAt', $changes ) ? $changes['completedAt'] : $this->completedAt
		);
	}

	/**
	 * Progress as a whole percentage, or null while the total is unknown.
	 */
	public function percent(): ?int {
		if ( $this->total <= 0 ) {
			return null;
		}

		return (int) min( 100, floor( ( $this->processed / $this->total ) * 100 ) );
	}
}
