<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Domain\ArchiveVolume;
use FictionDrafts\Domain\BackupJob;
use FictionDrafts\Domain\BackupProfile;
use FictionDrafts\Domain\JobStatus;
use FictionDrafts\Domain\StageCursor;
use FictionDrafts\Domain\StageResult;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase {

	public function testJobStatusSplitsActiveFromTerminal(): void {
		$this->assertTrue( JobStatus::Queued->isActive() );
		$this->assertTrue( JobStatus::Running->isActive() );

		$this->assertTrue( JobStatus::Completed->isTerminal() );
		$this->assertTrue( JobStatus::Failed->isTerminal() );
		$this->assertTrue( JobStatus::Cancelled->isTerminal() );
	}

	public function testBackupProfileHasNoWpConfigPredicate(): void {
		// wp-config.php inclusion is a per-job option, never a profile
		// property.  If this ever becomes a profile method, the decision
		// recorded in spec section 6.3 has been eroded.
		$this->assertFalse(
			method_exists( BackupProfile::class, 'includesWpConfig' ),
			'wp-config.php inclusion must stay a per-job option.'
		);
	}

	public function testStageCursorRoundTripsThroughJson(): void {
		$cursor = StageCursor::fromArray(
			[
				'table'  => 'wp_posts',
				'offset' => 500,
			]
		);

		$restored = StageCursor::fromJson( $cursor->toJson() );

		$this->assertSame( 'wp_posts', $restored->getString( 'table' ) );
		$this->assertSame( 500, $restored->getInt( 'offset' ) );
	}

	public function testAStartCursorIsEmpty(): void {
		$this->assertTrue( StageCursor::start()->isStart() );
		$this->assertFalse( StageCursor::fromArray( [ 'offset' => 1 ] )->isStart() );
	}

	public function testAnUnreadableCursorFallsBackToTheStartPosition(): void {
		// Restarting a stage is always safe; never resuming is not.
		$this->assertTrue( StageCursor::fromJson( 'not json at all' )->isStart() );
		$this->assertTrue( StageCursor::fromJson( null )->isStart() );
		$this->assertTrue( StageCursor::fromJson( '' )->isStart() );
	}

	public function testCursorAccessorsFallBackWhenTypesDoNotMatch(): void {
		$cursor = StageCursor::fromArray( [ 'table' => 42 ] );

		$this->assertSame( 'fallback', $cursor->getString( 'table', 'fallback' ) );
		$this->assertSame( 9, $cursor->getInt( 'absent', 9 ) );
	}

	public function testStageResultDistinguishesCompleteFromResumable(): void {
		$done = StageResult::complete( 10 );

		$this->assertTrue( $done->complete );
		$this->assertSame( 10, $done->processed );

		$more = StageResult::incomplete( StageCursor::fromArray( [ 'line' => 7 ] ), 7 );

		$this->assertFalse( $more->complete );
		$this->assertSame( 7, $more->cursor->getInt( 'line' ) );
	}

	public function testBackupJobExcludesWpConfigByDefault(): void {
		$job = new BackupJob( 'uuid-1', BackupProfile::Full );

		$this->assertFalse(
			$job->includesWpConfig(),
			'Even the Full profile must exclude wp-config.php unless opted in.'
		);
	}

	public function testBackupJobHonoursTheWpConfigOptIn(): void {
		$job = new BackupJob(
			'uuid-2',
			BackupProfile::Full,
			options: [ BackupJob::OPTION_INCLUDE_WP_CONFIG => true ]
		);

		$this->assertTrue( $job->includesWpConfig() );
	}

	public function testATruthyButNonBooleanOptInIsNotEnough(): void {
		$job = new BackupJob(
			'uuid-3',
			BackupProfile::Full,
			options: [ BackupJob::OPTION_INCLUDE_WP_CONFIG => '1' ]
		);

		$this->assertFalse( $job->includesWpConfig() );
	}

	public function testPercentIsNullUntilTheTotalIsKnown(): void {
		$job = new BackupJob( 'uuid-4', BackupProfile::Full );

		$this->assertNull( $job->percent() );
	}

	public function testPercentFloorsAndCaps(): void {
		$job = new BackupJob( 'uuid-5', BackupProfile::Full, processed: 1, total: 3 );
		$this->assertSame( 33, $job->percent() );

		$over = new BackupJob( 'uuid-6', BackupProfile::Full, processed: 12, total: 10 );
		$this->assertSame( 100, $over->percent() );
	}

	public function testAJobDefaultsToQueuedAndAStartCursor(): void {
		$job = new BackupJob( 'uuid-7', BackupProfile::DatabaseOnly );

		$this->assertSame( JobStatus::Queued, $job->status );
		$this->assertTrue( $job->cursor()->isStart() );
		$this->assertTrue( $job->isActive() );
	}

	public function testAVolumeIsSealedOnlyOnceItHasAChecksum(): void {
		$open = new ArchiveVolume( 'uuid-8', 1, 'part01.zip', '/tmp/part01.zip' );
		$this->assertFalse( $open->isSealed() );

		$sealed = new ArchiveVolume( 'uuid-8', 1, 'part01.zip', '/tmp/part01.zip', 1024, str_repeat( 'a', 64 ) );
		$this->assertTrue( $sealed->isSealed() );
	}
}
