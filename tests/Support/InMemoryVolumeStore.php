<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Support;

use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Persistence\VolumeStore;

/**
 * The volume ledger, in memory.
 *
 * Keyed on the job's uuid rather than its numeric id, so a job that was never
 * inserted still has a ledger — every test here builds jobs directly.
 */
final class InMemoryVolumeStore implements VolumeStore {

	/**
	 * @var array<string, array<int, ArchiveVolume>>
	 */
	public array $volumes = [];

	public int $replacements = 0;

	/**
	 * @param array<int, ArchiveVolume> $volumes Sealed volumes.
	 */
	public function replaceFor( BackupJob $job, array $volumes ): void {
		++$this->replacements;

		$this->volumes[ $job->uuid ] = array_values( $volumes );
	}

	/**
	 * @return array<int, ArchiveVolume>
	 */
	public function allFor( BackupJob $job ): array {
		return $this->volumes[ $job->uuid ] ?? [];
	}

	public function deleteFor( BackupJob $job ): void {
		unset( $this->volumes[ $job->uuid ] );
	}
}
