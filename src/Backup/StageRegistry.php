<?php

declare( strict_types=1 );

namespace FictionDrafts\Backup;

use FictionDrafts\Contracts\Stage;
use FictionDrafts\Domain\BackupJob;

/**
 * The stage pipeline, in order.
 *
 * Stages are resolved through the `fiction_drafts/stages` filter rather than a
 * hard-coded array, which is what lets a remote destination or an encrypting
 * step be added later without touching the runner.
 */
final class StageRegistry {

	public const FILTER = 'fiction_drafts/stages';

	/**
	 * Every registered stage, in pipeline order.
	 *
	 * @return array<int, Stage>
	 */
	public function all(): array {
		/** @var array<int, mixed> $stages */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the hook name is self::FILTER, 'fiction_drafts/stages'; the sniff cannot resolve a constant.
		$stages = apply_filters( self::FILTER, [] );

		return array_values(
			array_filter(
				$stages,
				static fn ( mixed $stage ): bool => $stage instanceof Stage
			)
		);
	}

	/**
	 * The stages that apply to one job, in order.
	 *
	 * @return array<int, Stage>
	 */
	public function applicableTo( BackupJob $job ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn ( Stage $stage ): bool => $stage->appliesTo( $job )
			)
		);
	}

	/**
	 * The stage with this id, if it applies to this job.
	 */
	public function find( BackupJob $job, string $id ): ?Stage {
		foreach ( $this->applicableTo( $job ) as $stage ) {
			if ( $stage->id() === $id ) {
				return $stage;
			}
		}

		return null;
	}

	/**
	 * The stage that follows $id, or null when $id is the last one.
	 */
	public function next( BackupJob $job, string $id ): ?Stage {
		$pipeline = $this->applicableTo( $job );

		foreach ( $pipeline as $index => $stage ) {
			if ( $stage->id() === $id ) {
				return $pipeline[ $index + 1 ] ?? null;
			}
		}

		return null;
	}

	public function first( BackupJob $job ): ?Stage {
		return $this->applicableTo( $job )[0] ?? null;
	}
}
