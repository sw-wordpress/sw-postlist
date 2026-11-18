<?php
/**
 * M8 acceptance: a reference extension (own source, formatter, layout and filter type) works end to end through the
 * documented contracts, and a faulty extension only produces diagnostics.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;
use SW\Postlist\Services;

class ExtensionContractTest extends SWP_TestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/example-extension/';

	public function tear_down(): void {
		remove_all_filters( 'sw_postlist_entry_sources' );
		remove_all_filters( 'sw_postlist_formatters' );
		remove_all_filters( 'sw_postlist_layouts' );
		remove_all_filters( 'sw_postlist_filter_types' );
		remove_all_filters( 'sw_postlist_presets' );
		InstanceRegistry::reset();
		ViewRenderer::reset();
		$_GET = array();
		parent::tear_down();
	}

	private function load( string $file ): void {
		require self::FIXTURES . $file;
		Services::reset();
		$this->services = Services::instance();
		InstanceRegistry::reset();
		ViewRenderer::reset();
		( new PostlistShortcode( $this->services->renderer() ) )->register();
	}

	/**
	 * @return array{view: int, entry: int}
	 */
	private function view_with_extension(): array {
		$this->admin();
		$entry = $this->services->templates()->save( null, $this->template_document( '<span class="ev">{{entry.title|shout}} ({{entry.region}})</span>', TemplateRole::Entry, 'Event card' ) );
		$view  = $this->services->views()->save(
			null,
			$this->view_document(
				array(
					'source'     => 'example:events',
					'query'      => array( 'per_page' => 2 ),
					'layout'     => array( 'type' => 'timeline', 'dense' => true ),
					'filters'    => array( array( 'type' => 'region' ), array( 'type' => 'search' ) ),
					'pagination' => array( 'mode' => 'ajax' ),
					'templates'  => array( 'entry' => $entry ),
				)
			)
		);
		wp_set_current_user( 0 );
		return array(
			'view'  => $view,
			'entry' => $entry,
		);
	}

	private function html( int $view, string $query = '' ): string {
		parse_str( $query, $_GET );
		InstanceRegistry::reset();
		ViewRenderer::reset();
		return do_shortcode( '[sw_postlist id="' . $view . '"]' );
	}

	public function test_the_example_extension_works_end_to_end_without_diagnostics(): void {
		$this->load( 'sw-postlist-example.php' );
		$this->assertSame( array(), $this->services->diagnostics(), 'The reference extension registers cleanly.' );
		$this->assertNotNull( $this->services->sources()->get( 'example:events' ) );
		$this->assertNotNull( $this->services->engine()->formatters()->get( 'shout' ) );
		$this->assertNotNull( $this->services->layouts()->get( 'timeline' ) );
		$this->assertNotNull( $this->services->filter_types()->get( 'region' ) );

		$ids  = $this->view_with_extension();
		$html = $this->html( $ids['view'] );
		$this->assertStringContainsString( '<ol class="example-timeline is-dense">', $html, 'The layout callback wraps the entries.' );
		$this->assertStringContainsString( '<li class="swp-item', $html, 'The layout asks for list items.' );
		$this->assertStringContainsString( 'SPRING FAIR! (north)', $html, 'Own formatter and own placeholders.' );
		$this->assertStringContainsString( 'SUMMER CONCERT!', $html );
		$this->assertStringNotContainsString( 'AUTUMN', $html, 'Two per page.' );
		$this->assertStringNotContainsString( 'STAFF', strtoupper( $html ), 'A non-public event never shows.' );
		$this->assertStringContainsString( 'swp-pagination', $html );
		$this->assertStringContainsString( 'name="swp[view-' . $ids['view'] . '-1][f][region]"', $html, 'The filter type draws its field.' );
	}

	public function test_extension_filter_source_and_visibility_contract_hold(): void {
		$this->load( 'sw-postlist-example.php' );
		$ids   = $this->view_with_extension();
		$key   = 'view-' . $ids['view'] . '-1';
		$north = $this->html( $ids['view'], 'swp[' . $key . '][f][region]=north' );
		$this->assertStringContainsString( 'SPRING FAIR!', $north );
		$this->assertStringContainsString( 'AUTUMN MARKET!', $north );
		$this->assertStringNotContainsString( 'SUMMER', $north );
		$this->assertStringNotContainsString( 'STAFF', strtoupper( $north ), 'The hidden north event stays hidden.' );
		$this->assertStringNotContainsString( 'STAFF', strtoupper( $this->html( $ids['view'], 'swp[' . $key . '][f][q]=party' ) ) );

		$page2 = $this->html( $ids['view'], 'swp[' . $key . '][page]=2' );
		$this->assertStringContainsString( 'AUTUMN MARKET!', $page2, 'Exact total of three public events gives a second page.' );
		$this->assertStringNotContainsString( 'SPRING', $page2 );
	}

	public function test_the_extension_works_through_the_public_route_and_export(): void {
		$this->load( 'sw-postlist-example.php' );
		$ids = $this->view_with_extension();
		global $wp_rest_server;
		$wp_rest_server = null;
		$request        = new WP_REST_Request( 'GET', '/sw-postlist/v1/views/' . $ids['view'] . '/render' );
		$request->set_param( 'key', 'view-' . $ids['view'] . '-1' );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( 3, $data['total'] );
		$this->assertTrue( $data['has_more'] );
		$this->assertStringContainsString( 'SPRING FAIR!', $data['html'] );

		$package = $this->services->packages()->export( array( $ids['view'] ) )['package'];
		$package = json_decode( (string) wp_json_encode( $package ), true );
		$dry     = $this->services->packages()->import( $package, true );
		$this->assertTrue( $dry->ok, (string) wp_json_encode( $dry->errors ) );
		$this->assertSame( 'timeline', $package['views'][0]['document']['layout']['type'] );
		$this->assertSame( 'region', $package['views'][0]['document']['filters'][0]['type'] );
	}

	public function test_without_the_extension_its_documents_are_refused_clearly(): void {
		$this->load( 'sw-postlist-example.php' );
		$ids     = $this->view_with_extension();
		$package = json_decode( (string) wp_json_encode( $this->services->packages()->export( array( $ids['view'] ) )['package'] ), true );

		remove_all_filters( 'sw_postlist_entry_sources' );
		remove_all_filters( 'sw_postlist_layouts' );
		remove_all_filters( 'sw_postlist_filter_types' );
		remove_all_filters( 'sw_postlist_formatters' );
		Services::reset();
		$services = Services::instance();
		$report   = $services->packages()->import( $package, true );
		$this->assertFalse( $report->ok );
		$codes = array_column( $report->errors, 'code' );
		$this->assertContains( 'layout_invalid', $codes );
		$this->assertContains( 'filter_invalid', $codes );
	}

	public function test_a_faulty_extension_produces_diagnostics_and_never_a_fatal_error(): void {
		$this->load( 'sw-postlist-faulty.php' );
		$codes = array_column( $this->services->diagnostics(), 'code' );
		foreach ( array( 'layout_id_invalid', 'layout_id_duplicate', 'layout_interface', 'filter_type_callback_invalid', 'filter_type_interface', 'formatter_interface' ) as $code ) {
			$this->assertContains( $code, $codes );
		}
		$this->assertNotEmpty( array_filter( $codes, static fn ( string $code ): bool => str_starts_with( $code, 'source_' ) ), 'Faulty sources are reported.' );
		$this->assertNotEmpty( array_filter( $codes, static fn ( string $code ): bool => str_starts_with( $code, 'preset_' ) ), 'Faulty presets are reported.' );
		$this->assertSame( array( 'grid', 'list', 'masonry', 'hover_grid', 'slider' ), $this->services->layouts()->ids(), 'Built-ins are untouched.' );
		$this->assertNotNull( $this->services->sources()->get( 'post:post' ) );

		$this->admin();
		$view = $this->services->views()->save( null, $this->view_document() );
		$this->assertStringContainsString( 'swp-view', $this->html( $view ), 'Rendering keeps working.' );
		$this->assertNotEmpty( $this->services->diagnostics() );
	}
}
