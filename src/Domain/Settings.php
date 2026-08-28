<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * Administrator preferences that outlive any single job.
 *
 * Persisted as one non-autoloaded option by SettingsRepository.  fromArray()
 * is the single coercion point: everything arriving from the option row or
 * from the REST layer passes through it, so no caller has to trust the shape
 * of what it was handed.
 */
final class Settings {

	/**
	 * 1.5 GiB — under the 2 GiB ceiling that 32-bit file handling and many
	 * reverse proxies impose, with room to spare.
	 */
	public const DEFAULT_MAX_VOLUME_BYTES = 1610612736;

	/**
	 * 10 MiB.  Smaller volumes produce more archive open/close cycles than
	 * they save in convenience.
	 */
	public const MIN_MAX_VOLUME_BYTES = 10485760;

	public const DEFAULT_RETENTION_COUNT = 5;

	private function __construct(
		private readonly BackupProfile $defaultProfile,
		private readonly ExclusionSet $exclusions,
		private readonly int $maxVolumeBytes,
		private readonly int $retentionCount
	) {}

	public static function defaults(): self {
		return new self(
			BackupProfile::Full,
			new ExclusionSet(),
			self::DEFAULT_MAX_VOLUME_BYTES,
			self::DEFAULT_RETENTION_COUNT
		);
	}

	/**
	 * Build a settings object, clamping any value outside its allowed range.
	 *
	 * Clamping rather than throwing is deliberate: these values arrive from a
	 * form and from a decade-old option row, and a backup plugin that refuses
	 * to load its own settings is worse than one that corrects them.
	 */
	public static function create(
		BackupProfile $defaultProfile,
		ExclusionSet $exclusions,
		int $maxVolumeBytes,
		int $retentionCount
	): self {
		return new self(
			$defaultProfile,
			$exclusions,
			max( self::MIN_MAX_VOLUME_BYTES, $maxVolumeBytes ),
			max( 0, $retentionCount )
		);
	}

	/**
	 * Hydrate from the stored option row.
	 *
	 * Every key is optional and every unrecognised value falls back to its
	 * default, so an absent, partial, or corrupted row still yields a usable
	 * object rather than a fatal.
	 *
	 * @param array<string, mixed> $data Raw option contents.
	 */
	public static function fromArray( array $data ): self {
		$profile = BackupProfile::Full;

		if ( isset( $data['default_profile'] ) && is_string( $data['default_profile'] ) ) {
			$profile = BackupProfile::tryFrom( $data['default_profile'] ) ?? BackupProfile::Full;
		}

		$patterns = [];

		if ( isset( $data['exclusions'] ) && is_array( $data['exclusions'] ) ) {
			$patterns = array_values( array_filter( $data['exclusions'], 'is_string' ) );
		}

		$maxVolumeBytes = self::DEFAULT_MAX_VOLUME_BYTES;

		if ( isset( $data['max_volume_bytes'] ) && is_numeric( $data['max_volume_bytes'] ) ) {
			$maxVolumeBytes = (int) $data['max_volume_bytes'];
		}

		$retentionCount = self::DEFAULT_RETENTION_COUNT;

		if ( isset( $data['retention_count'] ) && is_numeric( $data['retention_count'] ) ) {
			$retentionCount = (int) $data['retention_count'];
		}

		return self::create(
			$profile,
			new ExclusionSet( $patterns ),
			$maxVolumeBytes,
			$retentionCount
		);
	}

	/**
	 * @return array{default_profile: string, exclusions: array<int, string>, max_volume_bytes: int, retention_count: int}
	 */
	public function toArray(): array {
		return [
			'default_profile'  => $this->defaultProfile->value,
			'exclusions'       => $this->exclusions->patterns(),
			'max_volume_bytes' => $this->maxVolumeBytes,
			'retention_count'  => $this->retentionCount,
		];
	}

	public function defaultProfile(): BackupProfile {
		return $this->defaultProfile;
	}

	/**
	 * The administrator's own patterns, on top of the profile's defaults.
	 */
	public function exclusions(): ExclusionSet {
		return $this->exclusions;
	}

	public function maxVolumeBytes(): int {
		return $this->maxVolumeBytes;
	}

	/**
	 * How many completed backups to keep.  Zero means keep every one.
	 */
	public function retentionCount(): int {
		return $this->retentionCount;
	}

	public function withDefaultProfile( BackupProfile $profile ): self {
		return new self( $profile, $this->exclusions, $this->maxVolumeBytes, $this->retentionCount );
	}

	public function withExclusions( ExclusionSet $exclusions ): self {
		return new self( $this->defaultProfile, $exclusions, $this->maxVolumeBytes, $this->retentionCount );
	}

	public function withMaxVolumeBytes( int $bytes ): self {
		return self::create( $this->defaultProfile, $this->exclusions, $bytes, $this->retentionCount );
	}

	public function withRetentionCount( int $count ): self {
		return self::create( $this->defaultProfile, $this->exclusions, $this->maxVolumeBytes, $count );
	}
}
