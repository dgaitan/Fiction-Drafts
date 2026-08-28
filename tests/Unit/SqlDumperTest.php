<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Database\RowSerializer;
use FictionDrafts\Database\SqlDumper;
use FictionDrafts\Tests\Support\FakeDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the file, and the boundary that decides what may go into it.
 */
final class SqlDumperTest extends TestCase {

	private FakeDatabase $db;

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();

		$this->db = new FakeDatabase();
		$this->db->addTable(
			'wp_posts',
			[
				[
					'Field' => 'ID',
					'Type'  => 'bigint(20) unsigned',
					'Key'   => 'PRI',
					'Extra' => 'auto_increment',
				],
				[
					'Field' => 'post_title',
					'Type'  => 'text',
					'Key'   => '',
					'Extra' => '',
				],
			],
			[
				[
					'ID'         => '1',
					'post_title' => 'Hello',
				],
				[
					'ID'         => '2',
					'post_title' => null,
				],
			],
			"CREATE TABLE `wp_posts` (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT\n) ENGINE=InnoDB"
		);
		$this->db->addTable(
			'wp_empty',
			[
				[
					'Field' => 'id',
					'Type'  => 'int',
					'Key'   => 'PRI',
					'Extra' => '',
				],
			]
		);
		$this->db->addTable(
			'wp_options',
			[
				[
					'Field' => 'option_name',
					'Type'  => 'varchar(191)',
					'Key'   => 'PRI',
					'Extra' => '',
				],
			]
		);
	}

	private function dumper( bool $excludeTransients = true ): SqlDumper {
		return new SqlDumper(
			$this->db,
			new RowSerializer( $this->db ),
			[ 'wp_posts', 'wp_empty', 'wp_options' ],
			$excludeTransients
		);
	}

	public function testTheHeaderCarriesTheRequiredSessionSettings(): void {
		$header = $this->dumper()->header( [ 'Site' => 'https://example.test' ] );

		$this->assertStringContainsString( '-- Fiction Drafts export', $header );
		$this->assertStringContainsString( '-- Site: https://example.test', $header );
		$this->assertStringContainsString( 'SET NAMES utf8mb4;', $header );
		$this->assertStringContainsString( 'SET FOREIGN_KEY_CHECKS=0;', $header );
		$this->assertStringContainsString( "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';", $header );
	}

	public function testAMetaValueContainingANewlineCannotEscapeItsComment(): void {
		$header = $this->dumper()->header( [ 'Site' => "https://example.test\nDROP TABLE wp_posts;" ] );

		$this->assertStringNotContainsString( "\nDROP TABLE", $header );
		$this->assertStringContainsString( 'DROP TABLE wp_posts;', $header );
	}

	public function testTheFooterRestoresForeignKeyChecks(): void {
		$this->assertStringContainsString( 'SET FOREIGN_KEY_CHECKS=1;', $this->dumper()->footer() );
	}

	public function testASchemaBlockDropsThenCreatesVerbatim(): void {
		$block = $this->dumper()->schemaBlock( 'wp_posts' );

		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_posts`;', $block );
		$this->assertStringContainsString( 'CREATE TABLE `wp_posts` (', $block );
		$this->assertStringContainsString( 'ENGINE=InnoDB;', $block );
	}

	public function testATableOutsideTheAllowListIsRefused(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'allow-list' );

		$this->dumper()->schemaBlock( 'wp_users' );
	}

	public function testTheAllowListAlsoGuardsSchemaLookupAndInserts(): void {
		$dumper  = $this->dumper();
		$refused = 0;

		foreach ( [ 'schemaFor', 'schemaBlock' ] as $method ) {
			try {
				$dumper->{$method}( 'mysql.user' );
			} catch ( InvalidArgumentException ) {
				++$refused;
			}
		}

		try {
			$dumper->insertBatch( 'mysql.user', $dumper->schemaFor( 'wp_posts' ), 0, 10 );
		} catch ( InvalidArgumentException ) {
			++$refused;
		}

		$this->assertSame( 3, $refused );
	}

	public function testInsertsNameTheirColumnsExplicitly(): void {
		$dumper = $this->dumper();
		$batch  = $dumper->insertBatch( 'wp_posts', $dumper->schemaFor( 'wp_posts' ), 0, 500 );

		$this->assertStringStartsWith( 'INSERT INTO `wp_posts` (`ID`,`post_title`) VALUES ', $batch['sql'] );
		$this->assertSame( 2, $batch['rows'] );
	}

	public function testOneBatchIsOneStatementAndNullSurvivesIt(): void {
		$dumper = $this->dumper();
		$batch  = $dumper->insertBatch( 'wp_posts', $dumper->schemaFor( 'wp_posts' ), 0, 500 );

		$this->assertSame( 1, substr_count( $batch['sql'], 'INSERT INTO' ) );
		$this->assertStringContainsString( "(1,'Hello'),(2,NULL);", $batch['sql'] );
	}

	public function testABatchIsSplitWhenItWouldExceedTheStatementByteCap(): void {
		fiction_drafts_test_add_filter( SqlDumper::FILTER_MAX_INSERT_BYTES, static fn (): int => 1024 );

		$dumper = $this->dumper();
		$batch  = $dumper->insertBatch( 'wp_posts', $dumper->schemaFor( 'wp_posts' ), 0, 500 );

		// The cap floors at 1024 bytes and these rows are tiny, so one
		// statement is correct here; the split path is exercised below.
		$this->assertSame( 1, substr_count( $batch['sql'], 'INSERT INTO' ) );

		$this->db->addTable(
			'wp_posts',
			[
				[
					'Field' => 'ID',
					'Type'  => 'bigint',
					'Key'   => 'PRI',
					'Extra' => '',
				],
				[
					'Field' => 'body',
					'Type'  => 'longtext',
					'Key'   => '',
					'Extra' => '',
				],
			],
			[
				[
					'ID'   => '1',
					'body' => str_repeat( 'a', 800 ),
				],
				[
					'ID'   => '2',
					'body' => str_repeat( 'b', 800 ),
				],
				[
					'ID'   => '3',
					'body' => str_repeat( 'c', 800 ),
				],
			]
		);

		$dumper = $this->dumper();
		$batch  = $dumper->insertBatch( 'wp_posts', $dumper->schemaFor( 'wp_posts' ), 0, 500 );

		$this->assertSame( 3, substr_count( $batch['sql'], 'INSERT INTO' ) );
		$this->assertSame( 3, $batch['rows'] );
	}

	public function testAnEmptyTableProducesNoInsert(): void {
		$dumper = $this->dumper();
		$batch  = $dumper->insertBatch( 'wp_empty', $dumper->schemaFor( 'wp_empty' ), 0, 500 );

		$this->assertSame( '', $batch['sql'] );
		$this->assertSame( 0, $batch['rows'] );
	}

	public function testReadsAreOrderedByASingleColumnPrimaryKey(): void {
		$dumper = $this->dumper();
		$dumper->insertBatch( 'wp_posts', $dumper->schemaFor( 'wp_posts' ), 100, 500 );

		$select = end( $this->db->log );

		$this->assertStringContainsString( 'ORDER BY `ID` ASC', (string) $select );
		$this->assertStringContainsString( 'LIMIT 500 OFFSET 100', (string) $select );
	}

	public function testTheOptionsTableExcludesTransientsWithLikeLiteralUnderscores(): void {
		$dumper = $this->dumper();
		$dumper->insertBatch( 'wp_options', $dumper->schemaFor( 'wp_options' ), 0, 500 );

		$select = (string) end( $this->db->log );

		// Two backslashes in the SQL text: the string parser turns them into
		// one, and LIKE then reads `\_` as a literal underscore.  With a single
		// backslash the underscore stays a wildcard and `xtransientx` matches.
		$this->assertStringContainsString( "`option_name` NOT LIKE '\\\\_transient\\\\_%'", $select );
		$this->assertStringContainsString( "`option_name` NOT LIKE '\\\\_site\\\\_transient\\\\_%'", $select );
	}

	public function testTransientsAreKeptWhenTheJobAsksForThem(): void {
		$dumper = $this->dumper( false );
		$dumper->insertBatch( 'wp_options', $dumper->schemaFor( 'wp_options' ), 0, 500 );

		$this->assertStringNotContainsString( 'NOT LIKE', (string) end( $this->db->log ) );
	}

	public function testOnlyTheOptionsTableGetsTheTransientFilter(): void {
		$dumper = $this->dumper();
		$dumper->insertBatch( 'wp_posts', $dumper->schemaFor( 'wp_posts' ), 0, 500 );

		$this->assertStringNotContainsString( 'WHERE', (string) end( $this->db->log ) );
	}

	public function testTheResultSetIsReleasedAfterEveryBatch(): void {
		$dumper = $this->dumper();
		$before = $this->db->releases;

		$dumper->insertBatch( 'wp_posts', $dumper->schemaFor( 'wp_posts' ), 0, 500 );
		$dumper->insertBatch( 'wp_empty', $dumper->schemaFor( 'wp_empty' ), 0, 500 );

		$this->assertSame( $before + 2, $this->db->releases );
	}

	public function testTheBatchSizeDefaultsTo500AndIsFilterable(): void {
		$this->assertSame( 500, $this->dumper()->batchRows() );

		fiction_drafts_test_add_filter( SqlDumper::FILTER_BATCH_ROWS, static fn (): int => 25 );

		$this->assertSame( 25, $this->dumper()->batchRows() );
	}

	public function testTheHeaderPinsTheSessionTimeZoneToUtc(): void {
		$this->assertStringContainsString( "SET TIME_ZONE='+00:00';", $this->dumper()->header( [] ) );
	}

	public function testABatchSizeOfZeroIsClampedToOne(): void {
		fiction_drafts_test_add_filter( SqlDumper::FILTER_BATCH_ROWS, static fn (): int => 0 );

		$this->assertSame( 1, $this->dumper()->batchRows() );
	}
}
