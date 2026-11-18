<?php
/**
 * Lexer, parser and expression syntax details: positions, literals and recovery.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit\TemplateEngine;

use PHPUnit\Framework\TestCase;
use SW\Postlist\TemplateEngine\Syntax\ExpressionParser;
use SW\Postlist\TemplateEngine\Syntax\Position;

final class SyntaxTest extends TestCase {

	public function test_positions_are_one_based_lines_and_characters(): void {
		$source = "<p>\n  äöü {{x}}\n</p>";

		$this->assertSame( array( 1, 1 ), Position::at( $source, 0 ) );
		$this->assertSame( array( 2, 1 ), Position::at( $source, 4 ) );
		$this->assertSame( array( 2, 6 ), Position::at( $source, 4 + 2 + 6 ), 'Columns count characters, not bytes.' );
		$this->assertSame( array( 2, 7 ), Position::at( $source, 4 + 2 + 6 + 1 ) );
		$this->assertSame( array( 3, 1 ), Position::at( $source, strpos( $source, '</p>' ) ) );
		$this->assertSame( array( 3, 5 ), Position::at( $source, 9999 ) );
	}

	public function test_expression_literals(): void {
		$parsed = ExpressionParser::parse( 'entry.title | truncate:60 | default:"a \"b\" \\\\ c" | join:true | number:-2', 0 );

		$this->assertSame( 'entry.title', $parsed['name'] );
		$this->assertSame( array( 'truncate', 'default', 'join', 'number' ), array_map( static fn ( $call ) => $call->name, $parsed['chain'] ) );
		$this->assertSame( array( 60 ), $parsed['chain'][0]->params );
		$this->assertSame( array( 'a "b" \\ c' ), $parsed['chain'][1]->params );
		$this->assertSame( array( true ), $parsed['chain'][2]->params );
		$this->assertSame( array( -2 ), $parsed['chain'][3]->params );
	}

	public function test_separators_inside_strings_do_not_split(): void {
		$parsed = ExpressionParser::parse( 'entry.title|default:"a|b:c"', 0 );

		$this->assertSame( array( 'a|b:c' ), $parsed['chain'][0]->params );
	}

	public function test_invalid_expressions_report_codes(): void {
		foreach (
			array(
				'entry'                       => 'invalid_placeholder',
				'Entry.title'                 => 'invalid_placeholder',
				'entry.title|'                => 'invalid_formatter_syntax',
				'entry.title|Bad'             => 'invalid_formatter_syntax',
				'entry.title|f:'              => 'invalid_parameter',
				'entry.title|f:1.5'           => 'invalid_parameter',
				'entry.title|f:"a"b"'         => 'invalid_formatter_syntax',
				'entry.title|f:\'single\''    => 'invalid_parameter',
				'entry.title|f:99999999999'   => 'invalid_parameter',
				'entry.title|f:"unterminated' => 'invalid_formatter_syntax',
			) as $expression => $code
		) {
			$parsed = ExpressionParser::parse( $expression, 0 );
			$this->assertSame( $code, $parsed['code'] ?? 'none', $expression );
		}
	}

	public function test_dotted_dynamic_segments_may_contain_hyphens_and_digits(): void {
		$parsed = ExpressionParser::parse( 'entry.meta.event-date_2', 0 );

		$this->assertSame( 'entry.meta.event-date_2', $parsed['name'] );
	}
}
