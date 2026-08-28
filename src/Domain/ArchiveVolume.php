<?php

declare( strict_types=1 );

namespace FictionDrafts\Domain;

/**
 * One finished `.zip` file belonging to a backup.
 *
 * A backup is split across volumes so that no single file approaches a ZIP or
 * filesystem boundary, and so that no single download has to survive a proxy
 * timeout.  Sequence numbers start at 1.
 */
final class ArchiveVolume {

	public function __construct(
		public readonly string $jobUuid,
		public readonly int $sequence,
		public readonly string $filename,
		public readonly string $path,
		public readonly int $bytes = 0,
		public readonly string $sha256 = ''
	) {
	}

	public function isSealed(): bool {
		return '' !== $this->sha256;
	}

	/**
	 * @return array<string, scalar>
	 */
	public function toArray(): array {
		return [
			'job_uuid' => $this->jobUuid,
			'sequence' => $this->sequence,
			'filename' => $this->filename,
			'bytes'    => $this->bytes,
			'sha256'   => $this->sha256,
		];
	}
}
