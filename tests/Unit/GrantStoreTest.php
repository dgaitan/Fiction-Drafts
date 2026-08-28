<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Download\DownloadGrant;
use FictionDrafts\Download\OptionGrantStore;
use FictionDrafts\Tests\Support\NeverAcquiringLock;
use PHPUnit\Framework\TestCase;

/**
 * The single-use guarantee, and the decision that keeps a backup from being its
 * own key.
 *
 * The clock is injected so that expiry is proved by arithmetic rather than by
 * waiting five minutes, which is the only way this class could otherwise be
 * tested at all.
 */
final class GrantStoreTest extends TestCase {

	private const JOB = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private int $now = 1700000000;

	protected function setUp(): void {
		fiction_drafts_test_reset_options();
		fiction_drafts_test_reset_cache_deletes();
		$this->now = 1700000000;
	}

	private function store(): OptionGrantStore {
		return new OptionGrantStore( null, fn (): int => $this->now );
	}

	public function testAnIssuedGrantCanBeConsumedOnce(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$grant = $store->consume( $token );

		$this->assertInstanceOf( DownloadGrant::class, $grant );
		$this->assertSame( self::JOB, $grant->jobUuid );
		$this->assertSame( 1, $grant->sequence );
		$this->assertSame( 7, $grant->userId );
	}

	public function testASecondConsumeReturnsNothing(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$this->assertNotNull( $store->consume( $token ), 'the control: the first claim must succeed' );
		$this->assertNull( $store->consume( $token ) );
		$this->assertNull( $store->consume( $token ) );
	}

	/**
	 * The decision this class exists for.
	 *
	 * This plugin's whole job is to put `wp_options` into a downloadable
	 * archive. A grant stored in plaintext would be copied, still valid, into
	 * the very backup it authorises — so the archive would ship with working
	 * download links to itself.
	 */
	public function testTheStoredRecordIsNotTheToken(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$stored = wp_json_encode( get_option( OptionGrantStore::OPTION, [] ) );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( $token, $stored );
		// The control: the record genuinely is in there, so the assertion above
		// is about hashing rather than about an option that was never written.
		$this->assertStringContainsString( hash( 'sha256', $token ), $stored );
	}

	public function testTheTokenIsSixtyFourHexCharacters(): void {
		$token = $this->store()->issue( self::JOB, 1, 7 );

		$this->assertSame( 64, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
	}

	public function testTwoTokensAreNeverTheSame(): void {
		$store  = $this->store();
		$tokens = [];

		for ( $i = 0; $i < 25; ++$i ) {
			$tokens[] = $store->issue( self::JOB, 1, 7 );
		}

		$this->assertCount( 25, array_unique( $tokens ) );
	}

	public function testAGrantOlderThanFiveMinutesIsRefused(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$this->now += DownloadGrant::TTL_SECONDS;

		$this->assertNull( $store->consume( $token ) );
	}

	public function testAGrantJustInsideTheWindowIsAccepted(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$this->now += DownloadGrant::TTL_SECONDS - 1;

		$this->assertNotNull( $store->consume( $token ) );
	}

	public function testAnUnknownTokenIsRefused(): void {
		$this->assertNull( $this->store()->consume( str_repeat( 'a', 64 ) ) );
	}

	public function testAnEmptyTokenIsRefused(): void {
		$this->assertNull( $this->store()->consume( '' ) );
	}

	public function testExpiredRecordsAreSweptOnTheNextWrite(): void {
		$store = $this->store();
		$store->issue( self::JOB, 1, 7 );
		$store->issue( self::JOB, 2, 7 );

		$this->assertCount( 2, (array) get_option( OptionGrantStore::OPTION, [] ) );

		$this->now += DownloadGrant::TTL_SECONDS + 1;
		$store->issue( self::JOB, 3, 7 );

		// The two stale records are gone and only the fresh one remains: the
		// sweep is a property of using the store, not a cron job that may not
		// run on a quiet site.
		$this->assertCount( 1, (array) get_option( OptionGrantStore::OPTION, [] ) );
	}

	public function testTheStoreIsCappedSoItCannotBeGrownWithoutBound(): void {
		$store = $this->store();

		for ( $i = 0; $i < 120; ++$i ) {
			$store->issue( self::JOB, 1, 7 );
		}

		$this->assertLessThanOrEqual( 50, count( (array) get_option( OptionGrantStore::OPTION, [] ) ) );
	}

	public function testTheOptionIsNotAutoloaded(): void {
		$this->store()->issue( self::JOB, 1, 7 );

		$updates = fiction_drafts_test_option_calls()['update'];
		$written = array_values(
			array_filter(
				$updates,
				static fn ( array $call ): bool => OptionGrantStore::OPTION === $call['option']
			)
		);

		$this->assertNotEmpty( $written, 'the control: the option was written at all' );

		foreach ( $written as $call ) {
			$this->assertFalse( $call['autoload'], 'a grant store on every page load is a cost with no reader' );
		}
	}

	public function testTheOptionIsRemovedWhenTheLastGrantGoes(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$store->consume( $token );

		$this->assertSame( [], get_option( OptionGrantStore::OPTION, [] ) );
	}

	public function testFlushRemovesEverything(): void {
		$store = $this->store();
		$store->issue( self::JOB, 1, 7 );

		$store->flush();

		$this->assertSame( [], get_option( OptionGrantStore::OPTION, [] ) );
	}

	/**
	 * The store is an option, and an option is input — anything with database
	 * access can put a shape in there this code never wrote.
	 */
	public function testAMalformedRecordCannotAuthoriseAnything(): void {
		update_option(
			OptionGrantStore::OPTION,
			[
				hash( 'sha256', 'forged' ) => [
					'hash'      => hash( 'sha256', 'forged' ),
					'job'       => self::JOB,
					'sequence'  => '1',
					'user'      => [],
					'issued_at' => $this->now,
				],
			],
			false
		);

		$this->assertNull( $this->store()->consume( 'forged' ) );
	}

	public function testAStoreThatIsNotAnArrayIsIgnoredRatherThanFatal(): void {
		update_option( OptionGrantStore::OPTION, 'not an array', false );

		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$this->assertNotNull( $store->consume( $token ) );
	}

	public function testAGrantNamesTheVolumeItAuthorises(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 3, 7 );
		$grant = $store->consume( $token );

		$this->assertNotNull( $grant );
		$this->assertTrue( $grant->authorises( self::JOB, 3, 7 ) );
		$this->assertFalse( $grant->authorises( self::JOB, 4, 7 ), 'a grant for one volume is not a key to the next' );
		$this->assertFalse( $grant->authorises( self::JOB, 3, 8 ), 'a grant is bound to the user it was issued to' );
		$this->assertFalse(
			$grant->authorises( 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee', 3, 7 ),
			'a grant for one backup is not a key to another'
		);
	}

	/**
	 * The read inside the lock must be a real read.
	 *
	 * Non-autoloaded does not mean uncached. With a persistent object cache,
	 * `get_option()` answers from `wp_cache_get()`, and a copy taken before the
	 * lock was acquired says a token is unclaimed after another worker claimed
	 * it — single-use, twice. Nothing about the returned value can show this,
	 * because a development machine has no object cache: the only assertable
	 * fact is that the invalidation happened.
	 */
	public function testTheStoreInvalidatesTheObjectCacheBeforeReading(): void {
		$this->store()->issue( self::JOB, 1, 7 );

		$deletes = fiction_drafts_test_cache_deletes();

		$this->assertContains( 'options:' . OptionGrantStore::OPTION, $deletes );
		// `notoptions` is where WordPress remembers an option does NOT exist; a
		// stale entry there makes a freshly written store invisible.
		$this->assertContains( 'options:notoptions', $deletes );
	}

	public function testConsumeAlsoReadsPastTheCache(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		fiction_drafts_test_reset_cache_deletes();
		$store->consume( $token );

		$this->assertContains( 'options:' . OptionGrantStore::OPTION, fiction_drafts_test_cache_deletes() );
	}

	/**
	 * A grant that could not be written is not a grant.
	 *
	 * Returning the token anyway hands the administrator a link that refuses
	 * itself, with a message saying it has already been used.
	 */
	public function testIssueReturnsNothingWhenTheLockCannotBeTaken(): void {
		$store = new OptionGrantStore( new NeverAcquiringLock(), fn (): int => $this->now );

		$this->assertSame( '', $store->issue( self::JOB, 1, 7 ) );
		$this->assertSame( [], get_option( OptionGrantStore::OPTION, [] ) );
	}

	public function testConsumeRefusesWhenTheLockCannotBeTaken(): void {
		$store = $this->store();
		$token = $store->issue( self::JOB, 1, 7 );

		$locked = new OptionGrantStore( new NeverAcquiringLock(), fn (): int => $this->now );

		// Failing closed: unable to prove the token is unspent, refuse it.
		$this->assertNull( $locked->consume( $token ) );
		// And the control — the grant is still there for a caller that can take
		// the lock, so the refusal above is about the lock rather than about a
		// store that was empty.
		$this->assertNotNull( $store->consume( $token ) );
	}

	public function testExpiryIsFiveMinutes(): void {
		$grant = new DownloadGrant( str_repeat( 'a', 64 ), self::JOB, 1, 7, 1000 );

		$this->assertSame( 300, DownloadGrant::TTL_SECONDS );
		$this->assertSame( 1300, $grant->expiresAt() );
		$this->assertFalse( $grant->hasExpired( 1299 ) );
		$this->assertTrue( $grant->hasExpired( 1300 ) );
	}
}
