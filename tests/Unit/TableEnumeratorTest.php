<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Database\ColumnKind;
use FictionDrafts\Database\TableEnumerator;
use FictionDrafts\Database\TableSchema;
use FictionDrafts\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The allow-list, and the three ways a prefix match gets it wrong.
 */
final class TableEnumeratorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		fiction_drafts_test_reset_hooks();
	}

	/**
	 * @param array<int, string> $names Table names to register with no rows.
	 */
	private function database( array $names, string $prefix = 'wp_', string $basePrefix = 'wp_', bool $multisite = false ): FakeDatabase {
		$db = new FakeDatabase( $prefix, $basePrefix, $multisite );

		foreach ( $names as $name ) {
			$db->addTable( $name, [] );
		}

		return $db;
	}

	public function testItReturnsEveryTableBeginningWithThePrefix(): void {
		$db = $this->database( [ 'wp_posts', 'wp_options', 'wp_users' ] );

		$this->assertSame(
			[ 'wp_options', 'wp_posts', 'wp_users' ],
			( new TableEnumerator( $db ) )->forSite()
		);
	}

	public function testItBuildsAPreparedShowFullTablesLikeQuery(): void {
		$db = $this->database( [ 'wp_posts' ], 'wp_x_' );

		( new TableEnumerator( $db ) )->forSite();

		$this->assertSame( "SHOW FULL TABLES LIKE 'wp\\\\_x\\\\_%'", $db->log[0] );
	}

	public function testATableThatMerelyContainsThePrefixIsNotReturned(): void {
		$db = $this->database( [ 'wp_posts', 'other_wp_posts' ] );

		$this->assertSame( [ 'wp_posts' ], ( new TableEnumerator( $db ) )->forSite() );
	}

	public function testViewsAreExcluded(): void {
		$db = new FakeDatabase();
		$db->addTable( 'wp_posts', [] );
		$db->addTable( 'wp_a_view', [], [], '', 'VIEW' );

		$this->assertSame( [ 'wp_posts' ], ( new TableEnumerator( $db ) )->forSite() );
	}

	public function testOnMultisiteTheMainSiteDoesNotSweepInSubsiteTables(): void {
		$db = $this->database(
			[ 'wp_posts', 'wp_2_posts', 'wp_11_options', 'wp_blogs' ],
			'wp_',
			'wp_',
			true
		);

		$this->assertSame( [ 'wp_blogs', 'wp_posts' ], ( new TableEnumerator( $db ) )->forSite() );
	}

	public function testOnMultisiteASubsiteSeesOnlyItsOwnTables(): void {
		$db = $this->database( [ 'wp_2_posts', 'wp_2_options' ], 'wp_2_', 'wp_', true );

		$this->assertSame( [ 'wp_2_options', 'wp_2_posts' ], ( new TableEnumerator( $db ) )->forSite() );
	}

	public function testOnSingleSiteADigitPrefixedTableIsKept(): void {
		// Not multisite, so `wp_2_something` is just a table with an odd name
		// and dropping it would lose data.
		$db = $this->database( [ 'wp_posts', 'wp_2_legacy' ] );

		$this->assertSame( [ 'wp_2_legacy', 'wp_posts' ], ( new TableEnumerator( $db ) )->forSite() );
	}

	public function testTheOrderIsDeterministic(): void {
		$first  = ( new TableEnumerator( $this->database( [ 'wp_zeta', 'wp_alpha', 'wp_mid' ] ) ) )->forSite();
		$second = ( new TableEnumerator( $this->database( [ 'wp_mid', 'wp_zeta', 'wp_alpha' ] ) ) )->forSite();

		$this->assertSame( $first, $second );
		$this->assertSame( [ 'wp_alpha', 'wp_mid', 'wp_zeta' ], $first );
	}

	public function testTheExcludedTablesFilterRemovesNames(): void {
		fiction_drafts_test_add_filter(
			TableEnumerator::FILTER_EXCLUDED,
			static fn ( array $excluded ): array => [ ...$excluded, 'wp_options' ]
		);

		$db = $this->database( [ 'wp_posts', 'wp_options' ] );

		$this->assertSame( [ 'wp_posts' ], ( new TableEnumerator( $db ) )->forSite() );
	}

	public function testGeneratedColumnsAreExcludedFromTheInsertableSet(): void {
		$schema = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'id',
					'Type'  => 'bigint',
					'Key'   => 'PRI',
					'Extra' => 'auto_increment',
				],
				[
					'Field' => 'total',
					'Type'  => 'decimal(10,2)',
					'Key'   => '',
					'Extra' => 'STORED GENERATED',
				],
				[
					'Field' => 'shadow',
					'Type'  => 'varchar(10)',
					'Key'   => '',
					'Extra' => 'VIRTUAL GENERATED',
				],
			]
		);

		$this->assertSame( [ 'id' ], $schema->insertableColumns() );
	}

	public function testDefaultGeneratedIsNotAGeneratedColumn(): void {
		// `DEFAULT_GENERATED` is what Extra says for DEFAULT CURRENT_TIMESTAMP.
		// Ten columns in this plugin's own development database carry it, and a
		// substring test for "GENERATED" would drop every one of them.
		$schema = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'created_at',
					'Type'  => 'timestamp',
					'Key'   => '',
					'Extra' => 'DEFAULT_GENERATED',
				],
			]
		);

		$this->assertSame( [ 'created_at' ], $schema->insertableColumns() );
	}

	public function testACompositeKeyOrdersByAllOfItsColumns(): void {
		$single = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'id',
					'Type'  => 'bigint',
					'Key'   => 'PRI',
					'Extra' => '',
				],
			]
		);

		$composite = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'a',
					'Type'  => 'bigint',
					'Key'   => 'PRI',
					'Extra' => '',
				],
				[
					'Field' => 'b',
					'Type'  => 'bigint',
					'Key'   => 'PRI',
					'Extra' => '',
				],
			]
		);

		$this->assertSame( [ 'id' ], $single->orderColumns() );

		// `term_relationships` has a composite key and ships in every
		// WordPress install, so ordering by the first column of one is not a
		// hypothetical gap — and the first column alone is not a total order.
		$this->assertSame( [ 'a', 'b' ], $composite->orderColumns() );
	}

	public function testATableWithNoKeyOrdersByEverySortableColumn(): void {
		$schema = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'name',
					'Type'  => 'varchar(50)',
					'Key'   => '',
					'Extra' => '',
				],
				[
					'Field' => 'count',
					'Type'  => 'int',
					'Key'   => '',
					'Extra' => '',
				],
				[
					'Field' => 'body',
					'Type'  => 'longtext',
					'Key'   => '',
					'Extra' => '',
				],
				[
					'Field' => 'raw',
					'Type'  => 'longblob',
					'Key'   => '',
					'Extra' => '',
				],
			]
		);

		// Two rows agreeing in every sortable column are interchangeable, so a
		// window that swaps them changes nothing observable.  The text and blob
		// columns are left out: MySQL would copy them through the sort buffer,
		// which would cost more than the ordering is worth.
		$this->assertSame( [ 'name', 'count' ], $schema->orderColumns() );
	}

	public function testATableWithNothingSortableGetsNoOrder(): void {
		$schema = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'body',
					'Type'  => 'longtext',
					'Key'   => '',
					'Extra' => '',
				],
			]
		);

		$this->assertSame( [], $schema->orderColumns() );
	}

	/**
	 * @dataProvider columnKindProvider
	 */
	public function testColumnKindsAreClassifiedFromTheSqlType( string $type, ColumnKind $expected ): void {
		$this->assertSame( $expected, ColumnKind::fromSqlType( $type ) );
	}

	/**
	 * @return array<string, array{0: string, 1: ColumnKind}>
	 */
	public static function columnKindProvider(): array {
		return [
			'bigint'    => [ 'bigint(20) unsigned', ColumnKind::Numeric ],
			'int'       => [ 'int(11)', ColumnKind::Numeric ],
			'tinyint'   => [ 'tinyint(1)', ColumnKind::Numeric ],
			'decimal'   => [ 'decimal(10,2)', ColumnKind::Numeric ],
			'double'    => [ 'double', ColumnKind::Numeric ],
			'varchar'   => [ 'varchar(191)', ColumnKind::Text ],
			'longtext'  => [ 'longtext', ColumnKind::Text ],
			'datetime'  => [ 'datetime', ColumnKind::Text ],
			'longblob'  => [ 'longblob', ColumnKind::Binary ],
			'varbinary' => [ 'varbinary(64)', ColumnKind::Binary ],
			'binary'    => [ 'binary(16)', ColumnKind::Binary ],
			'bit'       => [ 'bit(1)', ColumnKind::Binary ],
		];
	}
}
