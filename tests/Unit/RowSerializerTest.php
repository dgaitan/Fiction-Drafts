<?php

declare( strict_types=1 );

namespace FictionDrafts\Tests\Unit;

use FictionDrafts\Database\ColumnKind;
use FictionDrafts\Database\RowSerializer;
use FictionDrafts\Database\TableSchema;
use FictionDrafts\Tests\Support\FakeDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Spec §6.4's value table, one assertion per row.
 *
 * Every one of these is a silent corruption if it goes the other way: the dump
 * still imports, the site still loads, and one column somewhere is wrong.
 */
final class RowSerializerTest extends TestCase {

	private RowSerializer $serializer;

	protected function setUp(): void {
		parent::setUp();

		$this->serializer = new RowSerializer( new FakeDatabase() );
	}

	public function testNullIsEmittedUnquoted(): void {
		$this->assertSame( 'NULL', $this->serializer->literal( null, ColumnKind::Text ) );
		$this->assertSame( 'NULL', $this->serializer->literal( null, ColumnKind::Numeric ) );
		$this->assertSame( 'NULL', $this->serializer->literal( null, ColumnKind::Binary ) );
	}

	public function testNullIsNeverEmittedAsAnEmptyString(): void {
		$this->assertNotSame( "''", $this->serializer->literal( null, ColumnKind::Text ) );
	}

	public function testAnEmptyStringIsNotEmittedAsNull(): void {
		$this->assertSame( "''", $this->serializer->literal( '', ColumnKind::Text ) );
	}

	public function testASingleQuoteIsEscapedAndTheValueQuoted(): void {
		$this->assertSame( "'O\\'Brien'", $this->serializer->literal( "O'Brien", ColumnKind::Text ) );
	}

	public function testANewlineIsEscapedRatherThanBreakingTheStatement(): void {
		$literal = $this->serializer->literal( "line\nbreak", ColumnKind::Text );

		$this->assertSame( "'line\\nbreak'", $literal );
		$this->assertStringNotContainsString( "\n", $literal );
	}

	public function testABackslashIsDoubled(): void {
		$this->assertSame( "'a\\\\b'", $this->serializer->literal( 'a\\b', ColumnKind::Text ) );
	}

	public function testABinaryValueIsEmittedAsAHexLiteral(): void {
		$this->assertSame( '0x00ff10', $this->serializer->literal( "\x00\xff\x10", ColumnKind::Binary ) );
	}

	public function testAnEmptyBinaryValueIsEmittedAsAnEmptyStringNotABareHexPrefix(): void {
		$this->assertSame( "''", $this->serializer->literal( '', ColumnKind::Binary ) );
	}

	public function testANumericValueIsEmittedUnquoted(): void {
		$this->assertSame( '42', $this->serializer->literal( '42', ColumnKind::Numeric ) );
		$this->assertSame( '-3.5', $this->serializer->literal( '-3.5', ColumnKind::Numeric ) );
	}

	public function testANonNumericValueInANumericColumnFallsBackToAQuotedString(): void {
		$this->assertSame( "''", $this->serializer->literal( '', ColumnKind::Numeric ) );
		$this->assertSame( "'n/a'", $this->serializer->literal( 'n/a', ColumnKind::Numeric ) );
	}

	public function testInvalidUtf8InATextColumnIsEmittedAsHex(): void {
		// A lone 0x80 continuation byte: valid latin1, invalid UTF-8.
		$this->assertSame( '0x61806200', $this->serializer->literal( "a\x80b\x00", ColumnKind::Text ) );
	}

	public function testAnEmojiIsQuotedRatherThanHexed(): void {
		$this->assertSame( "'🎈'", $this->serializer->literal( '🎈', ColumnKind::Text ) );
	}

	public function testATupleFollowsTheColumnOrderItIsGiven(): void {
		$schema = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'id',
					'Type'  => 'bigint(20) unsigned',
					'Key'   => 'PRI',
					'Extra' => 'auto_increment',
				],
				[
					'Field' => 'name',
					'Type'  => 'varchar(191)',
					'Key'   => '',
					'Extra' => '',
				],
				[
					'Field' => 'blob_col',
					'Type'  => 'longblob',
					'Key'   => '',
					'Extra' => '',
				],
			]
		);

		$tuple = $this->serializer->tuple(
			[
				'id'       => '7',
				'name'     => null,
				'blob_col' => "\x01\x02",
			],
			$schema->insertableColumns(),
			$schema
		);

		$this->assertSame( '(7,NULL,0x0102)', $tuple );
	}

	public function testAMissingColumnInARowIsTreatedAsNull(): void {
		$schema = TableSchema::fromShowColumns(
			[
				[
					'Field' => 'a',
					'Type'  => 'varchar(10)',
					'Key'   => '',
					'Extra' => '',
				],
			]
		);

		$this->assertSame( '(NULL)', $this->serializer->tuple( [], [ 'a' ], $schema ) );
	}
}
