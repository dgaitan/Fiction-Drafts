<?php

declare( strict_types=1 );

namespace FictionDrafts\Contracts;

use FictionDrafts\Domain\ArchiveVolume;

/**
 * Where a finished volume ends up.
 *
 * v0.1.0 ships only LocalDestination.  The contract exists so that remote
 * destinations (S3, Dropbox) are an added implementation plus an upload stage
 * rather than a rework of the engine.
 */
interface Destination {

	/**
	 * Stable machine identifier, e.g. `local`.
	 */
	public function id(): string;

	/**
	 * Translated, user-facing label.
	 */
	public function label(): string;

	/**
	 * Place a sealed volume at its final location.
	 */
	public function deliver( ArchiveVolume $volume ): void;

	/**
	 * Remove a volume — used by the retention sweep and by manual deletion.
	 */
	public function remove( ArchiveVolume $volume ): void;
}
