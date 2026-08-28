<?php

declare( strict_types=1 );

namespace FictionDrafts\Persistence;

use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\JobStatus;

/**
 * Where jobs are kept.
 *
 * This is an interface rather than a concrete class because StageRunner's
 * resumability guarantee has to be provable without a database.  The proof
 * runs the engine against an in-memory store, so what it demonstrates is the
 * engine's behaviour and not MySQL's.
 *
 * It deliberately does not live in src/Contracts/: that directory holds the
 * four architectural contracts the whole plugin is shaped around, and where
 * jobs are stored is a persistence detail, not one of them.
 */
interface JobStore {

	public function insert( BackupJob $job ): BackupJob;

	public function save( BackupJob $job ): BackupJob;

	public function findByUuid( string $uuid ): ?BackupJob;

	/**
	 * The one job that is queued or running, if there is one.
	 */
	public function findActive(): ?BackupJob;

	/**
	 * Jobs, newest first.
	 *
	 * The order is part of the contract, not an implementation detail: the
	 * retention sweep keeps the first N and deletes the rest, so a store that
	 * returned oldest-first would delete exactly the backups it was asked to
	 * keep.
	 *
	 * @param  JobStatus|null $status Restrict to one status, or null for all.
	 * @return array<int, BackupJob>
	 */
	public function all( ?JobStatus $status = null, int $limit = 50 ): array;

	/**
	 * Jobs left `running` with no update since $before (a MySQL datetime).
	 *
	 * @return array<int, BackupJob>
	 */
	public function findStale( string $before ): array;

	public function delete( string $uuid ): bool;
}
