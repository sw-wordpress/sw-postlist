<?php
/**
 * Result contract of the query service (ADR-05), independent of WordPress.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Port\EntryExtensionInterface;
use SW\Postlist\Application\Query\QueryService;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\QueryArgs;
use SW\Postlist\Domain\Source\VisitorFilters;
use SW\Postlist\Domain\View\ViewDocument;
use SW\Postlist\Tests\Support\FakeSource;
use SW\Postlist\Tests\Support\RecordingLogger;

final class QueryServiceTest extends TestCase {

	private RecordingLogger $logger;

	protected function setUp(): void {
		$this->logger = new RecordingLogger();
	}

	private function view( string $source = 'fake:items', int $per_page = 2, int $offset = 1 ): ViewDocument {
		$result = ( new ViewDocumentValidator() )->validate(
			array(
				'schema_version' => 1,
				'title'          => 'V',
				'source'         => $source,
				'query'          => array(
					'per_page' => $per_page,
					'offset'   => $offset,
				),
				'layout'         => array( 'type' => 'grid' ),
				'pagination'     => array( 'mode' => 'classic' ),
				'templates'      => array(),
			)
		);
		return $result->value();
	}

	private function entries(): array {
		return array( new Entry( 1, 'fake:items', array( 'title' => 'A' ) ), new Entry( 2, 'fake:items', array( 'title' => 'B' ) ) );
	}

	private function service( FakeSource $source, ?callable $extension = null ): QueryService {
		$extension_port = new class( $extension ) implements EntryExtensionInterface {
			public function __construct( private readonly mixed $callback ) {
			}
			public function filter_entries( array $entries, QueryArgs $args ): mixed {
				return null === $this->callback ? $entries : ( $this->callback )( $entries );
			}
		};
		return new QueryService( new SourceRegistry( array( $source ) ), $extension_port, $this->logger );
	}

	public function test_page_math_is_passed_to_the_source(): void {
		$source  = new FakeSource(
			'fake:items',
			$this->entries(),
			array(),
			array(
				'taxonomies'      => array(),
				'orderby'         => array(),
				'visitor_filters' => array(),
				'count_mode'      => 'exact',
			),
			5,
			true
		);
		$origin  = ContextOrigin::post( 7 );
		$visitor = new VisitorFilters( array(), array( 3 ) );

		$outcome = $this->service( $source )->run( $this->view(), 3, $visitor, $origin, 11, 4 );

		$this->assertTrue( $outcome->is_success() );
		$this->assertSame( 5, $outcome->result?->total );
		$this->assertCount( 1, $source->received );
		$args = $source->received[0];
		$this->assertSame( 5, $args->query_offset(), 'start offset 1 + (3 - 1) * 2' );
		$this->assertSame( 11, $args->view_id );
		$this->assertSame( 4, $args->user_id );
		$this->assertSame( $origin, $args->origin );
		$this->assertSame( $visitor, $args->visitor );
	}

	public function test_page_numbers_below_one_are_clamped(): void {
		$source = new FakeSource( 'fake:items', $this->entries() );
		$this->service( $source )->run( $this->view(), -4 );

		$this->assertSame( 1, $source->received[0]->page );
	}

	public function test_unknown_source_is_a_neutral_failure(): void {
		$outcome = $this->service( new FakeSource( 'fake:items' ) )->run( $this->view( 'fake:other' ) );

		$this->assertFalse( $outcome->is_success() );
		$this->assertSame( 'source_missing', $outcome->error_code );
		$this->assertSame( array( 'source_missing' ), $this->logger->codes );
	}

	public function test_extensions_may_enrich_data(): void {
		$source  = new FakeSource(
			'fake:items',
			$this->entries(),
			array(),
			array(
				'taxonomies'      => array(),
				'orderby'         => array(),
				'visitor_filters' => array(),
				'count_mode'      => 'continuation',
			),
			null,
			true
		);
		$service = $this->service( $source, static fn ( array $entries ): array => array_map( static fn ( Entry $entry ): Entry => $entry->with_data( $entry->data + array( 'badge' => 'new' ) ), $entries ) );

		$outcome = $service->run( $this->view() );

		$this->assertTrue( $outcome->is_success() );
		$this->assertSame( 'new', $outcome->result?->entries[0]->data['badge'] );
		$this->assertNull( $outcome->result?->total, 'A continuation source never reports a total.' );
		$this->assertTrue( $outcome->result?->has_more );
	}

	/**
	 * @return iterable<string, array{callable}>
	 */
	public static function violating_extensions(): iterable {
		yield 'reordered' => array( static fn ( array $entries ): array => array_reverse( $entries ) );
		yield 'entry added' => array( static fn ( array $entries ): array => array_merge( $entries, array( new Entry( 3, 'fake:items' ) ) ) );
		yield 'entry removed' => array( static fn ( array $entries ): array => array_slice( $entries, 0, 1 ) );
		yield 'identity changed' => array( static fn ( array $entries ): array => array( new Entry( 99, 'fake:items' ), $entries[1] ) );
		yield 'source changed' => array( static fn ( array $entries ): array => array( new Entry( 1, 'other:source' ), $entries[1] ) );
		yield 'not an entry' => array( static fn (): array => array( 'x', 'y' ) );
		yield 'not an array' => array( static fn (): string => 'nope' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'violating_extensions' )]
	public function test_extension_violations_fail_without_a_count( callable $extension ): void {
		$source  = new FakeSource(
			'fake:items',
			$this->entries(),
			array(),
			array(
				'taxonomies'      => array(),
				'orderby'         => array(),
				'visitor_filters' => array(),
				'count_mode'      => 'exact',
			),
			9,
			true
		);
		$outcome = $this->service( $source, $extension )->run( $this->view() );

		$this->assertFalse( $outcome->is_success() );
		$this->assertNull( $outcome->result );
		$this->assertSame( 'entries_contract_violation', $outcome->error_code );
		$this->assertSame( array( 'entries_contract_violation' ), $this->logger->codes );
	}

	public function test_can_view_is_the_last_line_of_defence(): void {
		$source  = new FakeSource(
			'fake:items',
			$this->entries(),
			array( 2 ),
			array(
				'taxonomies'      => array(),
				'orderby'         => array(),
				'visitor_filters' => array(),
				'count_mode'      => 'exact',
			),
			2
		);
		$outcome = $this->service( $source )->run( $this->view() );

		$this->assertFalse( $outcome->is_success() );
		$this->assertSame( 'visibility_violation', $outcome->error_code );
		$this->assertSame( array( 'visibility_violation' ), $this->logger->codes );
	}

	public function test_query_args_reject_page_zero(): void {
		$this->expectException( \InvalidArgumentException::class );
		new QueryArgs( 'fake:items', $this->view()->query, 0 );
	}

	public function test_visitor_filters_report_emptiness(): void {
		$this->assertTrue( ( new VisitorFilters() )->is_empty() );
		$this->assertFalse( ( new VisitorFilters( array( 'category' => array( 1 ) ) ) )->is_empty() );
		$this->assertFalse( ( new VisitorFilters( array(), array(), null, 'title' ) )->is_empty() );
	}

	public function test_origin_search_text_is_trimmed_and_bounded(): void {
		$origin = ContextOrigin::search( '  ' . str_repeat( 'ä', 300 ) . '  ' );

		$this->assertSame( ContextOrigin::MAX_SEARCH_LENGTH, mb_strlen( (string) $origin->search ) );
	}

	public function test_view_document_round_trip(): void {
		$view  = $this->view();
		$again = ( new ViewDocumentValidator() )->validate( $view->to_array() );

		$this->assertTrue( $again->is_valid() );
		$this->assertSame( $view->to_array(), $again->value()->to_array() );
	}
}
