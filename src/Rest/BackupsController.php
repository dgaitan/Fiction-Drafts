<?php

declare( strict_types=1 );

namespace FictionDrafts\Rest;

use FictionDrafts\Admin\ProfileCatalogue;
use FictionDrafts\Archive\VolumeNaming;
use FictionDrafts\Backup\BackupRemover;
use FictionDrafts\Backup\JobLock;
use FictionDrafts\Backup\Manifest;
use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Persistence\JobStore;
use FictionDrafts\Persistence\VolumeStore;
use FictionDrafts\Storage\StorageLocator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The finished backups, as a list an administrator can act on.
 *
 * ## The list is built from the rows, enriched by the manifests
 *
 * The tempting shape is the other way round: walk the storage directory, read
 * every sidecar manifest, render those.  It reads well and it is wrong, because
 * a backup whose manifest is missing or corrupt then disappears from the
 * screen — and a backup nobody can see is a backup nobody can delete, so the
 * disk it occupies never comes back.  The job row is the thing that always
 * exists; the manifest is enrichment, and its absence is reported as
 * `manifest: null` rather than by omitting the entry.
 *
 * ## Nothing here opens an archive
 *
 * Every figure this controller returns comes from a row or from the sidecar
 * JSON beside the volumes.  Opening a volume to count its entries would make
 * rendering a list of ten backups an O(gigabytes) operation, and the list is
 * the first thing the page loads.
 *
 * ## No paths, ever
 *
 * Spec §10.2: the client asks for a job by uuid and a volume by sequence, and
 * never learns or supplies a path.  `ArchiveVolume::path` is deliberately empty
 * when it comes back from the repository, and nothing here puts it back.
 */
final class BackupsController extends AbstractController {

	private const UUID_PATTERN = '(?P<uuid>[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})';

	public function __construct(
		private readonly JobStore $jobs,
		private readonly VolumeStore $volumes,
		private readonly StorageLocator $storage,
		private readonly BackupRemover $remover,
		private readonly ProfileCatalogue $profiles,
		private readonly ?JobLock $lock = null
	) {}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/backups',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'index' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args'                => [
					'per_page' => [
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups/' . self::UUID_PATTERN,
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'destroy' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
			]
		);
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$perPage = (int) $request->get_param( 'per_page' );
		$perPage = min( 100, max( 1, 0 === $perPage ? 20 : $perPage ) );

		$backups = array_map(
			fn ( BackupJob $job ): array => $this->present( $job ),
			$this->jobs->all( JobStatus::Completed, $perPage )
		);

		return $this->respond( [ 'backups' => $backups ] );
	}

	/**
	 * @param  WP_REST_Request<array<string, mixed>> $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ) {
		$job = $this->jobs->findByUuid( (string) $request->get_param( 'uuid' ) );

		if ( null === $job ) {
			return $this->error(
				'fiction_drafts_backup_not_found',
				__( 'That backup does not exist.', 'fiction-drafts' ),
				404
			);
		}

		// Deleting under a live worker is how a delete corrupts the thing it
		// is tidying: the archive stage still holds the volume open, and on
		// POSIX the inode survives the unlink until that process exits, so the
		// disk does not even come back promptly in exchange.  Cancel first —
		// that is what DELETE /jobs/{uuid} is for — and delete after.
		if ( $job->status->isActive() ) {
			return $this->error(
				'fiction_drafts_backup_running',
				__( 'That backup is still running. Cancel it first, then delete it.', 'fiction-drafts' ),
				409
			);
		}

		// The same named lock StageRunner takes for a step.
		//
		// Reading a status and then unlinking is read-then-act across two
		// processes: WP-Cron runs a worker in its own PHP process, and nothing
		// in the status check stops a queued job being claimed a millisecond
		// later.  Holding the step lock means a delete cannot overlap a step —
		// whoever holds it finishes first.
		//
		// This is the cheap half of the fix.  The complete one is a status the
		// runner compares-and-swaps on, so that a cancel or a delete is a flag
		// the runner acts on at a stage boundary rather than a race it usually
		// wins; that was already deferred to Sprint 7 with a written reason and
		// this does not change it.  What this closes is the common case: an
		// administrator clicking Delete while the queue happens to tick.
		if ( null !== $this->lock && ! $this->lock->acquire() ) {
			return $this->error(
				'fiction_drafts_backup_busy',
				__( 'Something else is working on this backup right now. Try again in a moment.', 'fiction-drafts' ),
				409
			);
		}

		try {
			// Re-read inside the lock. The status that mattered is the one
			// true while nothing else can be running, not the one read before.
			$job = $this->jobs->findByUuid( $job->uuid ) ?? $job;

			if ( $job->status->isActive() ) {
				return $this->error(
					'fiction_drafts_backup_running',
					__( 'That backup is still running. Cancel it first, then delete it.', 'fiction-drafts' ),
					409
				);
			}

			$this->remover->remove( $job );
		} finally {
			$this->lock?->release();
		}

		return $this->respond(
			[
				'uuid'    => $job->uuid,
				'deleted' => true,
			]
		);
	}

	/**
	 * One backup, as the list renders it.
	 *
	 * @return array<string, mixed>
	 */
	private function present( BackupJob $job ): array {
		$volumes  = $this->volumes->allFor( $job );
		$manifest = $this->manifestFor( $job );
		$onDisk   = $this->naming()->sequencesFor( $job );

		return [
			'uuid'               => $job->uuid,
			'created_at'         => $job->createdAt,
			'completed_at'       => $job->completedAt,
			// The same instants, unambiguously. A bare MySQL datetime handed
			// to `new Date()` is read as local time by some browsers and as
			// UTC by others, so a backup taken at 23:30 shows yesterday's
			// date on half the machines that look at it. These are always
			// UTC and always say so.
			'created_at_iso'     => self::iso( $job->createdAt ),
			'completed_at_iso'   => self::iso( $job->completedAt ),
			'profile'            => $this->profiles->describe( $job->profile ),
			'size_bytes'         => $job->sizeBytes,
			// Formatted here rather than in the client: `size_bytes` and the
			// string beside it must agree, and two implementations of "round
			// to one decimal" eventually will not.
			'size_human'         => self::humanBytes( $job->sizeBytes ),
			'volume_count'       => count( $volumes ),
			'volumes'            => array_map(
				static fn ( ArchiveVolume $volume ): array => [
					'sequence' => $volume->sequence,
					'filename' => $volume->filename,
					'bytes'    => $volume->bytes,
					'sha256'   => $volume->sha256,
				],
				$volumes
			),
			// Whether the files are actually there.  A row can outlive its
			// volumes — someone empties the storage directory by hand, a
			// restore of the database brings back rows the disk no longer
			// matches — and offering a download for a file that is gone is a
			// worse answer than saying so.
			'available'          => count( $onDisk ) > 0 && count( $onDisk ) === count( $volumes ),
			'includes_wp_config' => $this->includesWpConfig( $job, $manifest ),
			'manifest'           => $manifest,
		];
	}

	/**
	 * The sidecar manifest for this backup, or null when it cannot be read.
	 *
	 * @return array<string, mixed>|null
	 */
	private function manifestFor( BackupJob $job ): ?array {
		$raw = Manifest::read( $this->naming()->manifestPathFor( $job ) );

		return null === $raw ? null : self::project( $raw );
	}

	/**
	 * The sidecar, reduced to the keys this API promises.
	 *
	 * The sidecar is a file on disk, which means it is input.  Echoing whatever
	 * `json_decode` produced would make the response shape depend on a file
	 * anyone with write access to the storage directory can edit, and would put
	 * an arbitrarily deep structure — or an arbitrarily long one — in front of
	 * the browser.  Projecting through `Manifest::KEYS` makes the payload a
	 * contract rather than a passthrough: an unexpected key is dropped, an
	 * unreadable value becomes null, and the client can be written against a
	 * shape that cannot change underneath it.
	 *
	 * `volumes` is excluded deliberately.  The sidecar's copy is the ledger the
	 * archive wrote; the response already carries the ledger from the database,
	 * and two lists of volumes in one payload is an invitation to read the
	 * wrong one.
	 *
	 * @param  array<string, mixed> $raw Decoded sidecar contents.
	 * @return array<string, mixed>
	 */
	private static function project( array $raw ): array {
		$projected = [];

		foreach ( Manifest::KEYS as $key ) {
			if ( 'volumes' === $key ) {
				continue;
			}

			$value = $raw[ $key ] ?? null;

			$projected[ $key ] = self::scalarise( $value );
		}

		return $projected;
	}

	/**
	 * A value safe to hand to a browser, or null.
	 *
	 * A list of strings survives — `active_plugins` is one, and it is the whole
	 * point of the manifest.  Anything else structured is dropped rather than
	 * flattened, because a manifest that has grown a nested object is a
	 * manifest this version does not understand.
	 */
	private static function scalarise( mixed $value ): mixed {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$strings = array_values( array_filter( $value, 'is_string' ) );

		return count( $strings ) === count( $value ) ? array_slice( $strings, 0, 500 ) : null;
	}

	/**
	 * Does this archive carry the database password?
	 *
	 * Surfaced per backup, on the list, because it is the single fact that
	 * decides how carefully the file has to be handled — and an archive found
	 * a year later should not have to be opened to answer it.  The manifest is
	 * authoritative because it records what the job actually did; the job's own
	 * option is the fallback when the sidecar is unreadable.
	 *
	 * @param array<string, mixed>|null $manifest Sidecar contents, if readable.
	 */
	private function includesWpConfig( BackupJob $job, ?array $manifest ): bool {
		if ( null !== $manifest && isset( $manifest['includes_wp_config'] ) ) {
			return true === $manifest['includes_wp_config'];
		}

		return $job->includesWpConfig();
	}

	private function naming(): VolumeNaming {
		return new VolumeNaming( $this->storage->baseDir() );
	}

	/**
	 * A stored MySQL datetime, which is UTC throughout this plugin, as RFC 3339.
	 */
	private static function iso( ?string $mysql ): ?string {
		if ( null === $mysql || '' === $mysql ) {
			return null;
		}

		$stamp = strtotime( $mysql . ' UTC' );

		return false === $stamp ? null : gmdate( 'c', $stamp );
	}

	/**
	 * Bytes as a person reads them.
	 *
	 * Binary units, because that is what a filesystem reports and what the
	 * volume-size setting is expressed in; labelling 1,048,576 bytes "1 MB"
	 * next to a setting that calls it "1 MiB" invites the reader to conclude
	 * one of the two numbers is wrong.
	 */
	public static function humanBytes( int $bytes ): string {
		$units = [ 'B', 'KiB', 'MiB', 'GiB', 'TiB' ];
		$last  = count( $units ) - 1;
		$value = (float) max( 0, $bytes );
		$unit  = 0;

		while ( $value >= 1024 && $unit < $last ) {
			$value /= 1024;
			++$unit;
		}

		return 0 === $unit
			? sprintf( '%d %s', (int) $value, $units[ $unit ] )
			: sprintf( '%.1f %s', $value, $units[ $unit ] );
	}
}
