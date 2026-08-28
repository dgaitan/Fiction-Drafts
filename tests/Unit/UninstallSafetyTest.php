<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reads `uninstall.php` as text and asserts what it can and cannot do.
 *
 * The file can only be executed once, destructively, inside a real
 * WordPress — so its most important property is checked statically instead.
 * That property is not "it removes our data" but "it removes nothing else":
 * Action Scheduler's tables are shared with WooCommerce and any other plugin
 * that bundles the library, and dropping them here would break all of them at
 * the moment this plugin is deleted.
 */
final class UninstallSafetyTest extends TestCase {

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
	}

	public function testItRefusesToRunOutsideAnUninstall(): void {
		$this->assertStringContainsString( "! defined( 'WP_UNINSTALL_PLUGIN' )", $this->source() );
	}

	public function testItDropsTheJobsTable(): void {
		$this->assertStringContainsString( 'fdrafts_jobs', $this->source() );
	}

	public function testItDropsTheVolumesTable(): void {
		$this->assertStringContainsString( 'fdrafts_volumes', $this->source() );
	}

	/**
	 * The assertion this whole file exists for.
	 */
	public function testEveryDroppedTableIsOneWeOwn(): void {
		preg_match_all( '/DROP\s+TABLE[^;]*/i', $this->source(), $matches );

		$this->assertNotEmpty( $matches[0], 'uninstall must drop something' );

		foreach ( $matches[0] as $statement ) {
			$this->assertStringContainsString(
				'fiction_drafts_qualified',
				$statement,
				'every DROP must go through the fdrafts_-prefixed name list'
			);
		}
	}

	public function testItNeverMentionsTheActionSchedulerTables(): void {
		foreach ( [ 'actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs' ] as $table ) {
			$this->assertSame(
				0,
				preg_match( '/DROP\s+TABLE[^;]*' . $table . '/i', $this->source() ),
				$table . ' belongs to the shared library, not to this plugin'
			);
		}
	}

	public function testItDeletesOnlyOptionsWithOurPrefix(): void {
		preg_match_all( '/delete_option\(\s*\\$?([a-z_]+)/i', $this->source(), $matches );

		$this->assertNotEmpty( $matches[0] );

		// The loop deletes from a literal list; assert that list is ours.
		preg_match( '/foreach \(\s*\[([^\]]*)\]\s*as \\$fiction_drafts_option/', $this->source(), $list );

		$this->assertNotEmpty( $list, 'options must be deleted from an explicit list' );

		preg_match_all( "/'([^']+)'/", $list[1], $options );

		foreach ( $options[1] as $option ) {
			$this->assertStringStartsWith( 'fiction_drafts_', $option );
		}
	}

	public function testItUnschedulesOnlyOurActionGroup(): void {
		$this->assertStringContainsString( "as_unschedule_all_actions( '', [], 'fiction-drafts' )", $this->source() );
	}

	public function testItRemovesTheStorageDirectoryBeforeForgettingItsName(): void {
		$source = $this->source();

		$removal = strpos( $source, 'fiction_drafts_uninstall_rmdir( $fiction_drafts_base )' );
		$forget  = strpos( $source, 'delete_option( $fiction_drafts_option )' );

		$this->assertIsInt( $removal );
		$this->assertIsInt( $forget );
		$this->assertLessThan(
			$forget,
			$removal,
			'the slug option names the directory; deleting it first would orphan the archives'
		);
	}

	public function testItDoesNotFollowSymlinksWhenDeleting(): void {
		$this->assertStringContainsString( 'is_link( $path )', $this->source() );
	}
}
