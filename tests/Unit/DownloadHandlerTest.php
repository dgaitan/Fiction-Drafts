<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Download\DownloadGrant;
use FictionDrafts\Download\DownloadHandler;
use FictionDrafts\Download\OptionGrantStore;
use FictionDrafts\Rest\AbstractController;
use FictionDrafts\Storage\StorageLocator;
use FictionDrafts\Tests\Support\InMemoryJobStore;
use FictionDrafts\Tests\Support\InMemoryVolumeStore;
use FictionDrafts\Tests\Support\RecordingEmitter;
use FictionDrafts\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/**
 * The download endpoint, gate by gate and byte by byte.
 *
 * Everything asserted here is read back off a `RecordingEmitter` — the status,
 * every header, and the body itself. Nothing in this class concludes that a
 * header is right by looking at the code that sets it.
 */
final class DownloadHandlerTest extends TestCase {

	private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private const USER = 7;

	private TempTree $tree;

	private string $base;

	private InMemoryJobStore $jobs;

	private InMemoryVolumeStore $volumes;

	private OptionGrantStore $grants;

	private RecordingEmitter $emitter;

	private BackupJob $job;

	private string $contents;

	private string $filename;

	protected function setUp(): void {
		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_rest();
		fiction_drafts_test_set_logged_in( true );
		fiction_drafts_test_set_capability( AbstractController::CAPABILITY, true );
		fiction_drafts_test_set_user( self::USER );

		$this->tree = new TempTree();
		$this->base = $this->tree->dir( 'storage' );

		$this->jobs    = new InMemoryJobStore();
		$this->volumes = new InMemoryVolumeStore();
		$this->grants  = new OptionGrantStore();
		$this->emitter = new RecordingEmitter();

		$this->job = new BackupJob(
			uuid: self::UUID,
			profile: BackupProfile::Full,
			status: JobStatus::Completed,
			createdAt: '2026-08-28 10:00:00'
		);
		$this->jobs->insert( $this->job );

		// A body larger than one chunk would make every test slow; the chunk
		// loop is proved separately, against a real multi-chunk file.
		$this->contents = random_bytes( 4096 );
		$this->filename = $this->naming()->filenameFor( $this->job, 1 );
		$this->tree->file( 'storage/' . $this->filename, $this->contents );

		$this->volumes->replaceFor(
			$this->job,
			[ new ArchiveVolume( self::UUID, 1, $this->filename, '', 4096, str_repeat( 'a', 64 ) ) ]
		);
	}

	protected function tearDown(): void {
		$this->tree->remove();
		$_GET = [];
		fiction_drafts_test_reset_hooks();
		$GLOBALS['fiction_drafts_test_multisite'] = false;
		unset( $_SERVER['HTTP_RANGE'] );
	}

	private function naming(): \FictionDrafts\Archive\VolumeNaming {
		return new \FictionDrafts\Archive\VolumeNaming( $this->base );
	}

	private function handler(): DownloadHandler {
		return new DownloadHandler(
			$this->jobs,
			$this->volumes,
			new StorageLocator( $this->base ),
			$this->grants,
			$this->emitter
		);
	}

	/**
	 * A request with everything valid, unless a field is overridden.
	 *
	 * @param  array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function request( array $overrides = [] ): array {
		return array_merge(
			[
				'action'   => DownloadHandler::ACTION,
				'job'      => self::UUID,
				'volume'   => 1,
				'token'    => $this->grants->issue( self::UUID, 1, self::USER ),
				'_wpnonce' => wp_create_nonce( DownloadHandler::NONCE_ACTION ),
			],
			$overrides
		);
	}

	// ---------------------------------------------------------------- success

	/**
	 * The control for every refusal in this class.
	 *
	 * Without it, a handler that refused everything — a broken fixture, a
	 * mis-set capability — would pass every other test here.
	 */
	public function testAValidRequestServesTheArchive(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame( 200, $this->emitter->status );
		$this->assertSame( $this->contents, $this->emitter->body );
		$this->assertTrue( $this->emitter->finished );
	}

	public function testTheContentTypeIsZip(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame( 'application/zip', $this->emitter->headerValue( 'Content-Type' ) );
	}

	public function testTheResponseIsAnAttachmentNamedForTheVolume(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame(
			'attachment; filename="' . $this->filename . '"',
			$this->emitter->headerValue( 'Content-Disposition' )
		);
	}

	public function testTheContentLengthIsTheFileSize(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame( '4096', $this->emitter->headerValue( 'Content-Length' ) );
		// And it agrees with what was actually sent, which is the assertion
		// that would notice a length computed from the wrong thing.
		$this->assertSame( 4096, strlen( $this->emitter->body ) );
	}

	public function testTheResponseRefusesContentSniffing(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame( 'nosniff', $this->emitter->headerValue( 'X-Content-Type-Options' ) );
	}

	public function testTheResponseAdvertisesRangeSupport(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame( 'bytes', $this->emitter->headerValue( 'Accept-Ranges' ) );
	}

	public function testTheBuffersAreClearedBeforeAnythingIsSent(): void {
		$this->handler()->handle( $this->request() );

		$this->assertTrue( $this->emitter->buffersCleared );
		// Output already sent is headers already sent. Clearing after the first
		// header would be a PHP warning where a header should be.
		$this->assertTrue( $this->emitter->clearedBeforeFirstHeader );
	}

	public function testTheTimeLimitIsLifted(): void {
		$this->handler()->handle( $this->request() );

		$this->assertTrue( $this->emitter->timeLimitLifted );
	}

	public function testTheBodyIsFlushedAsItGoes(): void {
		$this->handler()->handle( $this->request() );

		$this->assertGreaterThan( 0, $this->emitter->flushes );
	}

	public function testAMultiChunkFileIsSentInChunksAndArrivesIntact(): void {
		$large = random_bytes( DownloadHandler::CHUNK_BYTES + 4096 );
		$name  = $this->naming()->filenameFor( $this->job, 2 );
		$this->tree->file( 'storage/' . $name, $large );
		$this->volumes->replaceFor(
			$this->job,
			[ new ArchiveVolume( self::UUID, 2, $name, '', strlen( $large ), str_repeat( 'b', 64 ) ) ]
		);

		$this->handler()->handle(
			$this->request(
				[
					'volume' => 2,
					'token'  => $this->grants->issue( self::UUID, 2, self::USER ),
				]
			)
		);

		$this->assertSame( 200, $this->emitter->status );
		// More than one write, and the first is exactly one chunk: the loop
		// reads a bounded amount rather than the whole file.
		$this->assertGreaterThan( 1, count( $this->emitter->chunkSizes ) );
		$this->assertSame( DownloadHandler::CHUNK_BYTES, $this->emitter->chunkSizes[0] );
		$this->assertSame( $large, $this->emitter->body );
	}

	// ------------------------------------------------------------------ range

	public function testARangeRequestIsAPartialResponse(): void {
		$_SERVER['HTTP_RANGE'] = 'bytes=1024-';

		$this->handler()->handle( $this->request() );

		$this->assertSame( 206, $this->emitter->status );
	}

	public function testThePartialResponseNamesTheSliceAndTheWhole(): void {
		$_SERVER['HTTP_RANGE'] = 'bytes=1024-';

		$this->handler()->handle( $this->request() );

		$this->assertSame( 'bytes 1024-4095/4096', $this->emitter->headerValue( 'Content-Range' ) );
		// The length of the slice, not of the file. A Content-Length of 4096
		// here leaves the client waiting for bytes that never come.
		$this->assertSame( '3072', $this->emitter->headerValue( 'Content-Length' ) );
	}

	public function testThePartialBodyIsExactlyThatSliceOfTheFile(): void {
		$_SERVER['HTTP_RANGE'] = 'bytes=1024-';

		$this->handler()->handle( $this->request() );

		$this->assertSame( substr( $this->contents, 1024 ), $this->emitter->body );
	}

	public function testAClosedRangeIsInclusiveOnBothEnds(): void {
		$_SERVER['HTTP_RANGE'] = 'bytes=0-1023';

		$this->handler()->handle( $this->request() );

		$this->assertSame( 206, $this->emitter->status );
		$this->assertSame( substr( $this->contents, 0, 1024 ), $this->emitter->body );
		$this->assertSame( 'bytes 0-1023/4096', $this->emitter->headerValue( 'Content-Range' ) );
	}

	public function testASuffixRangeIsTheEndOfTheFile(): void {
		$_SERVER['HTTP_RANGE'] = 'bytes=-1024';

		$this->handler()->handle( $this->request() );

		$this->assertSame( 206, $this->emitter->status );
		$this->assertSame( substr( $this->contents, -1024 ), $this->emitter->body );
	}

	public function testAnUnsatisfiableRangeIsRefusedWithTheRealLength(): void {
		$_SERVER['HTTP_RANGE'] = 'bytes=99999-';

		$this->handler()->handle( $this->request() );

		$this->assertSame( 416, $this->emitter->status );
		$this->assertSame( 'bytes */4096', $this->emitter->headerValue( 'Content-Range' ) );
		$this->assertSame( '', $this->emitter->body );
	}

	public function testAMalformedRangeIsIgnoredRatherThanRefused(): void {
		$_SERVER['HTTP_RANGE'] = 'chapters=1-2';

		$this->handler()->handle( $this->request() );

		$this->assertSame( 200, $this->emitter->status );
		$this->assertSame( $this->contents, $this->emitter->body );
	}

	// ------------------------------------------------- production divergences

	/**
	 * nginx spools a FastCGI response to disk unless told not to.
	 *
	 * `flush()` reaches nginx and stops there, so the whole archive lands in
	 * `fastcgi_temp` before a byte reaches the client — and a multi-gigabyte
	 * backup can fill that partition. Invisible on any machine serving PHP
	 * directly.
	 */
	public function testTheResponseTellsNginxNotToBuffer(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame( 'no', $this->emitter->headerValue( 'X-Accel-Buffering' ) );
	}

	public function testTheResponseCarriesAValidatorForResumes(): void {
		$this->handler()->handle( $this->request() );

		$etag = $this->emitter->headerValue( 'ETag' );

		$this->assertIsString( $etag );
		$this->assertMatchesRegularExpression( '/^"[a-f0-9]{32}"$/', (string) $etag );
	}

	/**
	 * A resume into a file that is no longer the same file.
	 *
	 * Without a validator the client's byte offset is applied to a different
	 * archive, and the two halves join into one plausible-looking corrupt zip.
	 * RFC 9110 §13.1.5: a non-matching `If-Range` means send the whole thing.
	 */
	public function testAResumeIntoADifferentFileGetsTheWholeFileBack(): void {
		$_SERVER['HTTP_RANGE']    = 'bytes=1024-';
		$_SERVER['HTTP_IF_RANGE'] = '"0000000000000000000000000000dead"';

		$this->handler()->handle( $this->request() );

		$this->assertSame( 200, $this->emitter->status );
		$this->assertSame( $this->contents, $this->emitter->body );

		unset( $_SERVER['HTTP_IF_RANGE'] );
	}

	public function testAResumeIntoTheSameFileStillGetsItsRange(): void {
		$this->handler()->handle( $this->request() );
		$etag = (string) $this->emitter->headerValue( 'ETag' );

		$this->emitter            = new RecordingEmitter();
		$_SERVER['HTTP_RANGE']    = 'bytes=1024-';
		$_SERVER['HTTP_IF_RANGE'] = $etag;

		$this->handler()->handle( $this->request() );

		// The control for the test above: the same request with a matching
		// validator is still a 206, so the 200 there was about the mismatch.
		$this->assertSame( 206, $this->emitter->status );

		unset( $_SERVER['HTTP_IF_RANGE'] );
	}

	/**
	 * A BOM or a stray notice elsewhere sends the headers early. Every header
	 * after that is a no-op and the archive is appended to whatever went out —
	 * a 200 carrying a file that will not open.
	 */
	public function testAResponseThatAlreadyStartedIsRefusedRatherThanCorrupted(): void {
		$this->emitter->alreadySent = true;

		$this->handler()->handle( $this->request() );

		$this->assertSame( 500, $this->emitter->status );
		$this->assertNotSame( $this->contents, $this->emitter->body );
	}

	public function testStreamingStopsWhenTheClientHangsUp(): void {
		$large = random_bytes( DownloadHandler::CHUNK_BYTES + 4096 );
		$name  = $this->naming()->filenameFor( $this->job, 2 );
		$this->tree->file( 'storage/' . $name, $large );
		$this->volumes->replaceFor(
			$this->job,
			[ new ArchiveVolume( self::UUID, 2, $name, '', strlen( $large ), str_repeat( 'b', 64 ) ) ]
		);

		$this->emitter->aborted = true;

		$this->handler()->handle(
			$this->request(
				[
					'volume' => 2,
					'token'  => $this->grants->issue( self::UUID, 2, self::USER ),
				]
			)
		);

		// The headers went out; the body did not. A worker draining a
		// multi-gigabyte file nobody is receiving is how the pool runs out.
		$this->assertSame( 200, $this->emitter->status );
		$this->assertSame( '', $this->emitter->body );
	}

	/**
	 * The control for the test above.
	 */
	public function testStreamingContinuesWhenTheClientIsStillThere(): void {
		$this->handler()->handle( $this->request() );

		$this->assertSame( $this->contents, $this->emitter->body );
	}

	// -------------------------------------------------------------- refusals

	public function testAReplayedTokenIsRefused(): void {
		$request = $this->request();

		$this->handler()->handle( $request );
		$this->assertSame( 200, $this->emitter->status, 'the control: the first use must succeed' );

		$this->emitter = new RecordingEmitter();
		$this->handler()->handle( $request );

		$this->assertSame( 403, $this->emitter->status );
		$this->assertNotSame( $this->contents, $this->emitter->body );
	}

	public function testATokenIssuedToAnotherUserIsRefused(): void {
		$request = $this->request( [ 'token' => $this->grants->issue( self::UUID, 1, 99 ) ] );

		$this->handler()->handle( $request );

		$this->assertSame( 403, $this->emitter->status );
	}

	public function testAnExpiredTokenIsRefused(): void {
		$now    = 1700000000;
		$grants = new OptionGrantStore( null, static fn (): int => $now );
		$token  = $grants->issue( self::UUID, 1, self::USER );

		$stale = new OptionGrantStore( null, static fn (): int => $now + DownloadGrant::TTL_SECONDS + 1 );

		$handler = new DownloadHandler(
			$this->jobs,
			$this->volumes,
			new StorageLocator( $this->base ),
			$stale,
			$this->emitter
		);

		$handler->handle(
			[
				'job'      => self::UUID,
				'volume'   => 1,
				'token'    => $token,
				'_wpnonce' => wp_create_nonce( DownloadHandler::NONCE_ACTION ),
			]
		);

		$this->assertSame( 403, $this->emitter->status );
	}

	public function testATokenForAnotherVolumeIsRefused(): void {
		$this->handler()->handle(
			$this->request( [ 'token' => $this->grants->issue( self::UUID, 2, self::USER ) ] )
		);

		$this->assertSame( 403, $this->emitter->status );
	}

	public function testAMissingNonceIsRefused(): void {
		$this->handler()->handle( $this->request( [ '_wpnonce' => '' ] ) );

		$this->assertSame( 403, $this->emitter->status );
	}

	public function testAWrongNonceIsRefused(): void {
		$this->handler()->handle( $this->request( [ '_wpnonce' => wp_create_nonce( 'something_else' ) ] ) );

		$this->assertSame( 403, $this->emitter->status );
	}

	public function testALoggedOutRequestIsRefused(): void {
		fiction_drafts_test_set_logged_in( false );

		$this->handler()->handle( $this->request() );

		$this->assertSame( 403, $this->emitter->status );
	}

	public function testARequestWithoutTheCapabilityIsRefused(): void {
		fiction_drafts_test_set_capability( AbstractController::CAPABILITY, false );

		$this->handler()->handle( $this->request() );

		$this->assertSame( 403, $this->emitter->status );
	}

	/**
	 * Spec §10.2's known gap, closed.
	 *
	 * `manage_options` is per site; on a network install it belongs to every
	 * subsite administrator while the archives belong to one site.
	 */
	public function testOnMultisiteASiteAdministratorIsNotEnough(): void {
		$GLOBALS['fiction_drafts_test_multisite'] = true;
		fiction_drafts_test_set_capability( AbstractController::CAPABILITY, true );
		fiction_drafts_test_set_capability( 'manage_network_options', false );

		$this->handler()->handle( $this->request() );

		$this->assertSame( 403, $this->emitter->status );
	}

	public function testOnMultisiteANetworkAdministratorIsEnough(): void {
		$GLOBALS['fiction_drafts_test_multisite'] = true;
		fiction_drafts_test_set_capability( 'manage_network_options', true );

		$this->handler()->handle( $this->request() );

		$this->assertSame( 200, $this->emitter->status );
	}

	public function testNoRefusalEverOpensTheFile(): void {
		foreach ( [
			'_wpnonce' => '',
			'token'    => 'nope',
		] as $field => $value ) {
			$this->emitter = new RecordingEmitter();

			$this->handler()->handle( $this->request( [ $field => $value ] ) );

			$this->assertSame( 403, $this->emitter->status );
			// A refusal that read the file first would be measurably slower for
			// a real volume than for a missing one — an oracle telling an
			// attacker which uuids exist.
			$this->assertStringNotContainsString( $this->contents, $this->emitter->body );
		}
	}

	// ------------------------------------------------------------ no paths

	public function testACraftedPathParameterChangesNothing(): void {
		$this->tree->file( 'outside.txt', 'secret' );

		$this->handler()->handle(
			$this->request(
				[
					'path' => $this->tree->path( 'outside.txt' ),
					'file' => '../../outside.txt',
				]
			)
		);

		// The archive, and only the archive: the handler reads `job` and
		// `volume` and nothing else.
		$this->assertSame( 200, $this->emitter->status );
		$this->assertSame( $this->contents, $this->emitter->body );
	}

	public function testAVolumeThatIsNotPartOfTheBackupIsNotFound(): void {
		foreach ( [ 0, 5, 999999 ] as $sequence ) {
			$this->emitter = new RecordingEmitter();

			$this->handler()->handle(
				$this->request(
					[
						'volume' => $sequence,
						'token'  => $this->grants->issue( self::UUID, $sequence, self::USER ),
					]
				)
			);

			$this->assertContains(
				$this->emitter->status,
				[ 403, 404 ],
				'sequence ' . $sequence . ' must not be served'
			);
			$this->assertNotSame( $this->contents, $this->emitter->body );
		}
	}

	public function testAnUnknownJobIsNotFound(): void {
		$other = 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee';

		$this->handler()->handle(
			$this->request(
				[
					'job'   => $other,
					'token' => $this->grants->issue( $other, 1, self::USER ),
				]
			)
		);

		$this->assertSame( 404, $this->emitter->status );
	}

	public function testAVolumeReplacedBySymlinkIsRefused(): void {
		$this->tree->file( 'outside.txt', 'secret' );
		unlink( $this->base . '/' . $this->filename );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filesystem without symlink support raises a warning here, and the branch below is the proper error checking the sniff asks for.
		if ( ! @symlink( $this->tree->path( 'outside.txt' ), $this->base . '/' . $this->filename ) ) {
			$this->markTestSkipped( 'This filesystem does not support symlinks.' );
		}

		$this->handler()->handle( $this->request() );

		$this->assertSame( 404, $this->emitter->status );
		$this->assertStringNotContainsString( 'secret', $this->emitter->body );
	}

	public function testAVolumeRemovedFromDiskIsRefusedRatherThanFatal(): void {
		unlink( $this->base . '/' . $this->filename );

		$this->handler()->handle( $this->request() );

		$this->assertSame( 404, $this->emitter->status );
	}

	public function testAnIncompleteBackupIsNotServed(): void {
		$this->jobs->save( $this->job->with( [ 'status' => JobStatus::Running ] ) );

		$this->handler()->handle( $this->request() );

		$this->assertSame( 404, $this->emitter->status );
	}

	// ------------------------------------------------- the hook, as WP fires it

	/**
	 * The regression this class was missing.
	 *
	 * Every other test here calls `handle( [...] )` directly, which proves the
	 * gates and proves nothing about how WordPress reaches them. Core dispatches
	 * `admin_post_{$action}` with no arguments and substitutes an empty string
	 * for the first one, so the handler is called as `handle( '' )` — a shape no
	 * test in this file produced, and the shape that fataled in production.
	 */
	public function testTheEmptyStringWordPressPassesIsNotAFatal(): void {
		$_GET = [];

		$this->handler()->handle( '' );

		$this->assertSame( 403, $this->emitter->status );
	}

	/**
	 * The control for the test above, and the one that exercises the wiring.
	 *
	 * `register()` and `handle()` were each tested; the composition of the two
	 * was not. This fires the real action through the real registration with the
	 * arguments core supplies, and expects the archive — so a handler that
	 * refused everything, or a registration that attached nothing, fails here.
	 */
	public function testFiringTheActionTheWayCoreDoesServesTheArchive(): void {
		fiction_drafts_test_reset_hooks();

		$_GET = $this->request();

		$this->handler()->register();

		do_action( 'admin_post_' . DownloadHandler::ACTION );

		$this->assertSame( 200, $this->emitter->status );
		$this->assertSame( $this->contents, $this->emitter->body );
	}

	/**
	 * An array argument still wins over the superglobal, which is what keeps
	 * every other test in this class free of `$_GET`.
	 */
	public function testAnInjectedArrayIsPreferredToTheSuperglobal(): void {
		$_GET = $this->request( [ 'job' => 'not-the-right-job' ] );

		$this->handler()->handle( $this->request() );

		$this->assertSame( 200, $this->emitter->status );
	}
}
