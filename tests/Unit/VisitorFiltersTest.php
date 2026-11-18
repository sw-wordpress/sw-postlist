<?php
/**
 * Visitor filter input: only configured filters are read, values are bounded and third-party callbacks are contained.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Filter\FilterTypeDefinition;
use SW\Postlist\Application\Filter\FilterTypeRegistry;
use SW\Postlist\Application\Filter\VisitorInputParser;

final class VisitorFiltersTest extends TestCase {

	private function parser( mixed $extensions = array() ): VisitorInputParser {
		return new VisitorInputParser( new FilterTypeRegistry( $extensions ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function filters(): array {
		return array(
			array(
				'type'     => 'taxonomy',
				'taxonomy' => 'category',
				'display'  => 'buttons',
			),
			array(
				'type'     => 'taxonomy',
				'taxonomy' => 'post_tag',
				'terms'    => array( 7, 8, 9 ),
			),
			array( 'type' => 'author' ),
			array( 'type' => 'search' ),
			array(
				'type'    => 'sort',
				'options' => array( 'date', 'title' ),
			),
		);
	}

	public function test_valid_input_becomes_visitor_filters_and_normalized_parameters(): void {
		$selection = $this->parser()->parse(
			$this->filters(),
			array(
				'tax_category' => '3,4',
				'tax_post_tag' => array( '7', '9', '5' ),
				'author'       => '2',
				'q'            => '  hello   world ',
				'sort'         => 'title:asc',
			)
		);
		$visitor   = $selection->visitor;
		$this->assertSame(
			array(
				'category' => array( 3, 4 ),
				'post_tag' => array( 7, 9 ),
			),
			$visitor->terms
		);
		$this->assertSame( array( 2 ), $visitor->authors );
		$this->assertSame( 'hello   world', $visitor->search );
		$this->assertSame( 'title', $visitor->orderby );
		$this->assertSame( 'asc', $visitor->order );
		$this->assertSame( array( '3', '4' ), $selection->values( 'tax_category' ) );
		$this->assertSame( array( 'title:asc' ), $selection->values( 'sort' ) );
		$this->assertSame( array(), $selection->values( 'unknown' ) );
	}

	public function test_unconfigured_filters_and_garbage_are_ignored(): void {
		$selection = $this->parser()->parse(
			$this->filters(),
			array(
				'tax_category' => array( 'x', '-3', '0', '1e3', array( '5' ), '99999999999', '12abc' ),
				'tax_secret'   => '1',
				'author'       => array( 'a' => array( 'b' ) ),
				'q'            => array( array( 'nested' ) ),
				'sort'         => 'password:asc',
				'unrelated'    => 'x',
			)
		);
		$this->assertTrue( $selection->visitor->is_empty() );
		$this->assertSame( array(), array_filter( $selection->params ) );
	}

	public function test_sort_accepts_only_offered_fields_and_directions(): void {
		foreach ( array( 'meta_num:asc', 'date:sideways', 'date:asc:extra', '', ':asc' ) as $raw ) {
			$this->assertNull( $this->parser()->parse( $this->filters(), array( 'sort' => $raw ) )->visitor->orderby, $raw );
		}
		$this->assertSame( 'date', $this->parser()->parse( $this->filters(), array( 'sort' => 'date' ) )->visitor->orderby );
		$this->assertSame( 'desc', $this->parser()->parse( $this->filters(), array( 'sort' => 'date' ) )->visitor->order );
	}

	public function test_values_are_bounded(): void {
		$many      = implode( ',', range( 1, 500 ) );
		$selection = $this->parser()->parse(
			$this->filters(),
			array(
				'tax_category' => $many,
				'q'            => str_repeat( 'ä', 500 ) . "\x00\x1f",
			)
		);
		$this->assertCount( VisitorInputParser::MAX_VALUES, $selection->visitor->terms['category'] );
		$this->assertSame( 100, mb_strlen( (string) $selection->visitor->search ) );
		$this->assertStringNotContainsString( "\x00", (string) $selection->visitor->search );
		$this->assertTrue( $this->parser()->parse( $this->filters(), 'not an array' )->visitor->is_empty() );
	}

	public function test_third_party_filter_results_are_sanitized_again(): void {
		$type   = new FilterTypeDefinition(
			'region',
			'Region',
			array(),
			static fn (): array => array(
				'terms'   => array(
					'region'  => array( 4, '5', -1, 6 ),
					'Bad Tax' => array( 1 ),
				),
				'authors' => array( 9, 'x' ),
				'search'  => 'north',
				'orderby' => 'password',
				'order'   => 'up',
			),
			static fn (): string => ''
		);
		$result = $this->parser( array( $type ) )->parse( array( array( 'type' => 'region' ) ), array( 'region' => 'north' ) );
		$this->assertSame( array( 'region' => array( 4, 6 ) ), $result->visitor->terms );
		$this->assertSame( array( 9 ), $result->visitor->authors );
		$this->assertSame( 'north', $result->visitor->search );
		$this->assertNull( $result->visitor->orderby );
		$this->assertNull( $result->visitor->order );
		$this->assertSame( array( 'north' ), $result->values( 'region' ) );
	}

	public function test_failing_third_party_callbacks_are_reported_not_thrown(): void {
		$throws = new FilterTypeDefinition(
			'boom',
			'Boom',
			array(),
			static function (): array {
				throw new \RuntimeException( 'x' );
			},
			static fn (): string => ''
		);
		$scalar = new FilterTypeDefinition( 'scalar', 'Scalar', array(), static fn (): string => 'oops', static fn (): string => '' );
		$result = $this->parser( array( $throws, $scalar ) )->parse( array( array( 'type' => 'boom' ), array( 'type' => 'scalar' ), array( 'type' => 'ghost' ) ), array( 'boom' => '1' ) );
		$this->assertSame( array( 'boom', 'scalar', 'ghost' ), $result->problems );
		$this->assertTrue( $result->visitor->is_empty() );
	}

	public function test_registry_reports_faulty_registrations(): void {
		$ok       = static fn (): string => '';
		$registry = new FilterTypeRegistry(
			array(
				'junk',
				new FilterTypeDefinition( 'search', 'Duplicate', array(), $ok, $ok ),
				new FilterTypeDefinition( 'Bad Id', 'Bad', array(), $ok, $ok ),
				new FilterTypeDefinition( 'nolabel', ' ', array(), $ok, $ok ),
				new FilterTypeDefinition( 'badschema', 'Schema', array( 'x' => array( 'type' => 'magic' ) ), $ok, $ok ),
				new FilterTypeDefinition( 'enumless', 'Enum', array( 'x' => array( 'type' => 'enum_list' ) ), $ok, $ok ),
				new FilterTypeDefinition( 'nocallbacks', 'None' ),
				new FilterTypeDefinition(
					'good',
					'Good',
					array(
						'size' => array(
							'type' => 'int',
							'min'  => 1,
							'max'  => 3,
						),
					),
					$ok,
					$ok
				),
			)
		);
		$codes    = array_column( $registry->diagnostics(), 'code' );
		foreach ( array( 'filter_type_interface', 'filter_type_id_duplicate', 'filter_type_id_invalid', 'filter_type_label_empty', 'filter_type_schema_invalid', 'filter_type_callback_invalid' ) as $code ) {
			$this->assertContains( $code, $codes );
		}
		$this->assertSame( array( 'taxonomy', 'author', 'search', 'sort', 'good' ), array_keys( $registry->all() ) );
		$this->assertTrue( $registry->is_builtin( 'sort' ) );
		$this->assertFalse( $registry->is_builtin( 'good' ) );
		$this->assertSame( 'Search', $registry->get( 'search' )?->label );
		$this->assertSame( array( 'filter_types_not_array' ), array_column( ( new FilterTypeRegistry( 'x' ) )->diagnostics(), 'code' ) );
	}

	/**
	 * @param array<string, mixed> $filters Filters section.
	 * @return list<string>
	 */
	private function validate( array $filters ): array {
		$result = ( new ViewDocumentValidator() )->validate(
			array(
				'schema_version' => 1,
				'title'          => 'T',
				'source'         => 'post:post',
				'layout'         => array( 'type' => 'grid' ),
				'pagination'     => array( 'mode' => 'classic' ),
				'filters'        => $filters,
			)
		);
		return $result->is_valid() ? array() : $result->error_codes();
	}

	public function test_document_validation_of_filters(): void {
		$this->assertSame( array(), $this->validate( $this->filters() ) );
		$this->assertSame( array(), $this->validate( array() ) );
		$this->assertContains( 'filters_invalid', $this->validate( array( 'a' => 1 ) ) );
		$this->assertContains( 'filters_invalid', $this->validate( array_fill( 0, 7, array( 'type' => 'search' ) ) ) );
		$this->assertContains( 'filter_invalid', $this->validate( array( array( 'type' => 'nope' ) ) ) );
		$this->assertContains( 'filter_invalid', $this->validate( array( 'search' ) ) );
		$this->assertContains(
			'filter_unknown_key',
			$this->validate(
				array(
					array(
						'type'     => 'search',
						'taxonomy' => 'x',
					),
				)
			)
		);
		$this->assertContains( 'filter_option_invalid', $this->validate( array( array( 'type' => 'taxonomy' ) ) ), 'A taxonomy filter needs a taxonomy.' );
		$this->assertContains(
			'filter_option_invalid',
			$this->validate(
				array(
					array(
						'type'     => 'taxonomy',
						'taxonomy' => 'Bad Slug',
					),
				)
			)
		);
		$this->assertContains(
			'filter_option_invalid',
			$this->validate(
				array(
					array(
						'type'     => 'taxonomy',
						'taxonomy' => 'category',
						'display'  => 'radio',
					),
				)
			)
		);
		$this->assertContains(
			'filter_option_invalid',
			$this->validate(
				array(
					array(
						'type'     => 'taxonomy',
						'taxonomy' => 'category',
						'terms'    => array( 0 ),
					),
				)
			)
		);
		$this->assertContains(
			'filter_option_invalid',
			$this->validate(
				array(
					array(
						'type'     => 'taxonomy',
						'taxonomy' => 'category',
						'label'    => '<b>x</b>',
					),
				)
			)
		);
		$this->assertContains(
			'filter_option_invalid',
			$this->validate(
				array(
					array(
						'type'    => 'sort',
						'options' => array( 'date', 'date' ),
					),
				)
			)
		);
		$this->assertContains(
			'filter_option_invalid',
			$this->validate(
				array(
					array(
						'type'    => 'sort',
						'options' => array( 'password' ),
					),
				)
			)
		);
		$this->assertContains( 'filter_duplicate', $this->validate( array( array( 'type' => 'search' ), array( 'type' => 'search' ) ) ) );
		$this->assertContains(
			'filter_duplicate',
			$this->validate(
				array(
					array(
						'type'     => 'taxonomy',
						'taxonomy' => 'category',
					),
					array(
						'type'     => 'taxonomy',
						'taxonomy' => 'category',
					),
				)
			)
		);
	}
}
