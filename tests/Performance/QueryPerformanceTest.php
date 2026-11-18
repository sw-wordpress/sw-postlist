<?php
/**
 * Reproducible query measurement with a representative data set (PLAN.md §9, M9: about 2,000 entries).
 *
 * Writes the measurements as JSON to $SWP_PERF_REPORT (default: coverage/performance.json). The budgets are
 * deliberately generous regression guards for CI runners; the recorded numbers are what matters.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Source\VisitorFilters;

class QueryPerformanceTest extends SWP_TestCase {

	private const ENTRIES = 2000;

	private const BUDGET_MS = 750;

	/** @var array<string, float> */
	private array $timings = array();

	public function test_queries_on_two_thousand_entries(): void {
		$categories = array();
		foreach ( range( 1, 5 ) as $number ) {
			$categories[] = self::factory()->category->create( array( 'name' => 'Perf ' . $number ) );
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk seeding of a throw-away test database.
		for ( $index = 1; $index <= self::ENTRIES; $index++ ) {
			self::factory()->post->create(
				array(
					'post_title'    => 'Perf entry ' . $index,
					'post_category' => array( $categories[ $index % 5 ] ),
					'post_date'     => gmdate( 'Y-m-d H:i:s', 1700000000 + $index * 3600 ),
					'meta_input'    => array( 'rank' => (string) ( $index % 97 ) ),
				)
			);
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.

		$by_category = $this->view_document(
			array(
				'query' => array(
					'per_page'   => 12,
					'orderby'    => 'date',
					'order'      => 'desc',
					'taxonomies' => array(
						array(
							'taxonomy' => 'category',
							'terms'    => array( $categories[0] ),
							'operator' => 'in',
						),
					),
				),
			)
		);
		$plain       = $this->view_document(
			array(
				'query' => array(
					'per_page' => 12,
					'orderby'  => 'date',
					'order'    => 'desc',
				),
			)
		);
		$by_meta     = $this->view_document(
			array(
				'query' => array(
					'per_page'       => 12,
					'orderby'        => 'meta_num',
					'order'          => 'desc',
					'order_meta_key' => 'rank',
				),
			)
		);

		$this->measure( 'first page', fn () => $this->services->query()->run( $plain, 1 ) );
		$this->measure( 'deep page (100)', fn () => $this->services->query()->run( $plain, 100 ) );
		$this->measure( 'empty page behind the end', fn () => $this->services->query()->run( $plain, 500 ) );
		$this->measure( 'category filter', fn () => $this->services->query()->run( $by_category, 1 ) );
		$this->measure( 'visitor category filter', fn () => $this->services->query()->run( $plain, 1, new VisitorFilters( array( 'category' => array( $categories[1] ) ) ) ) );
		$this->measure( 'visitor search', fn () => $this->services->query()->run( $plain, 1, new VisitorFilters( array(), array(), 'entry 19' ) ) );
		$this->measure( 'numeric meta sort', fn () => $this->services->query()->run( $by_meta, 1 ) );

		// Full render path: query, entry templates, layout, filter form with term and author lists, pagination.
		$filtered                                    = $this->services->views()->save(
			null,
			$this->view_document(
				array(
					'query'   => array(
						'per_page' => 12,
						'orderby'  => 'date',
						'order'    => 'desc',
					),
					'filters' => array(
						array(
							'type'     => 'taxonomy',
							'taxonomy' => 'category',
						),
						array( 'type' => 'author' ),
						array( 'type' => 'search' ),
					),
				)
			)
		);
		$render                                      = function () use ( $filtered ) {
			\SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry::reset();
			\SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer::reset();
			return $this->services->renderer()->render( $filtered, new \SW\Postlist\Infrastructure\WordPress\Render\RenderRequest( 'perf', 1 ) );
		};
		$start                                       = hrtime( true );
		$result                                      = $render();
		$this->timings['render with filters (cold)'] = round( ( hrtime( true ) - $start ) / 1e6, 1 );
		$this->assertNull( $result->error_code );
		$start = hrtime( true );
		$render();
		$this->timings['render with filters (warm)'] = round( ( hrtime( true ) - $start ) / 1e6, 1 );

		$report = array(
			'entries'   => self::ENTRIES,
			'budget_ms' => self::BUDGET_MS,
			'timings'   => $this->timings,
		);
		$path   = getenv( 'SWP_PERF_REPORT' ) ?: dirname( __DIR__, 2 ) . '/coverage/performance.json';
		if ( ! is_dir( dirname( $path ) ) ) {
			wp_mkdir_p( dirname( $path ) );
		}
		file_put_contents( $path, wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test report.
		fwrite( STDERR, "\n" . wp_json_encode( $this->timings ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test output.

		foreach ( $this->timings as $name => $milliseconds ) {
			$this->assertLessThan( self::BUDGET_MS, $milliseconds, $name . ' exceeded the budget' );
		}
	}

	private function measure( string $name, callable $run ): void {
		$start                  = hrtime( true );
		$outcome                = $run();
		$this->timings[ $name ] = round( ( hrtime( true ) - $start ) / 1e6, 1 );
		$this->assertTrue( $outcome->is_success(), $name );
	}
}
