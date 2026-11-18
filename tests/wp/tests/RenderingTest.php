<?php
/**
 * M3: server rendering of grid and list, shortcode, block, REST preview, pagination and context origin.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\OriginSigner;
use SW\Postlist\Infrastructure\WordPress\Render\RenderRequest;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Block\ViewBlock;
use SW\Postlist\Presentation\Rest\ViewsController;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;

class RenderingTest extends SWP_TestCase {

	private int $term;

	/** @var list<int> */
	private array $published = array();

	public function set_up(): void {
		parent::set_up();
		InstanceRegistry::reset();
		ViewRenderer::reset();
		$_GET = array();
		( new PostlistShortcode( $this->services->renderer() ) )->register();
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( ViewBlock::NAME ) ) {
			unregister_block_type( ViewBlock::NAME );
		}
		( new ViewBlock( $this->services->renderer() ) )->register();
		$this->term = self::factory()->category->create( array( 'name' => 'Match' ) );
	}

	public function tear_down(): void {
		$_GET = array();
		InstanceRegistry::reset();
		ViewRenderer::reset();
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( ViewBlock::NAME ) ) {
			unregister_block_type( ViewBlock::NAME );
		}
		parent::tear_down();
	}

	private function seed_acceptance_content(): void {
		$base = array( 'post_category' => array( $this->term ) );
		foreach ( range( 1, 6 ) as $number ) {
			$this->published[] = self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Published ' . $number ) ) );
		}
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Draft entry', 'post_status' => 'draft' ) ) );
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Private entry', 'post_status' => 'private' ) ) );
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Protected entry', 'post_password' => 'secret' ) ) );
	}

	/**
	 * @param array<string, mixed> $overrides Overrides merged over the acceptance view.
	 */
	private function acceptance_view( array $overrides = array(), string $status = 'publish' ): int {
		$document = $this->view_document(
			array_replace_recursive(
				array(
					'title' => 'Acceptance',
					'query' => array(
						'per_page'   => 2,
						'offset'     => 1,
						'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => array( $this->term ), 'operator' => 'in' ) ),
					),
				),
				$overrides
			)
		);
		return $this->services->views()->save( null, $document, $status );
	}

	private function items( string $html ): int {
		return substr_count( $html, 'class="swp-item' );
	}

	public function test_acceptance_grid_and_list_through_shortcode_and_block(): void {
		$this->seed_acceptance_content();
		$grid = $this->acceptance_view( array( 'layout' => array( 'type' => 'grid' ) ) );
		$list = $this->acceptance_view( array( 'layout' => array( 'type' => 'list' ) ) );

		foreach ( array( 1 => 2, 2 => 2, 3 => 1 ) as $page => $expected ) {
			$_GET = $page > 1 ? array( 'swp' => array( 'view-' . $grid . '-1' => array( 'page' => (string) $page ) ) ) : array();
			InstanceRegistry::reset();
			ViewRenderer::reset();
			$html = do_shortcode( '[sw_postlist id="' . $grid . '"]' );
			$this->assertSame( $expected, $this->items( $html ), 'Shortcode grid page ' . $page );
			$this->assertStringContainsString( '<div class="swp-grid">', $html );
			$this->assertStringContainsString( 'swp-layout-grid', $html );
			$this->assertStringContainsString( 'swp-tpl-builtin-container-grid', $html );
			$this->assertStringNotContainsString( 'Draft entry', $html );
			$this->assertStringNotContainsString( 'Private entry', $html );
			$this->assertStringNotContainsString( 'Protected entry', $html );

			$_GET = $page > 1 ? array( 'swp' => array( 'view-' . $list . '-1' => array( 'page' => (string) $page ) ) ) : array();
			InstanceRegistry::reset();
			ViewRenderer::reset();
			$html = do_blocks( '<!-- wp:sw-postlist/view {"viewId":' . $list . '} /-->' );
			$this->assertSame( $expected, $this->items( $html ), 'Block list page ' . $page );
			$this->assertStringContainsString( '<ul class="swp-list">', $html );
			$this->assertStringContainsString( '<li class="swp-item', $html );
		}
		$this->assertStringContainsString( 'Published 2', do_shortcode( '[sw_postlist id="' . $grid . '"]' ), 'The start offset skips the first entry.' );
	}

	public function test_rest_preview_matches_the_rendered_counts(): void {
		$this->seed_acceptance_content();
		$view_id = $this->acceptance_view();
		$this->admin();

		foreach ( array( 1 => 2, 2 => 2, 3 => 1, 4 => 0 ) as $page => $expected ) {
			$request  = new WP_REST_Request( 'GET', '/sw-postlist/v1/views/' . $view_id . '/preview' );
			$request->set_param( 'page', $page );
			$response = rest_get_server()->dispatch( $request );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 5, $data['total'], 'HTML and REST report the same visible total after the start offset.' );
			$this->assertSame( $expected, $data['count'] );
			$this->assertSame( $expected, $this->items( $data['html'] ) );
			$this->assertSame( $page, $data['page'] );
			$this->assertSame( 3 > $page, $data['has_more'] );
			$this->assertStringNotContainsString( '<style', $data['html'], 'The REST response carries the CSS separately.' );
			$this->assertIsString( $data['css'] );
		}
	}

	public function test_preview_route_is_protected_and_serves_drafts_to_managers_only(): void {
		$draft = $this->acceptance_view( array(), 'draft' );
		$this->create_posts( 2 );

		$request = new WP_REST_Request( 'GET', '/sw-postlist/v1/views/' . $draft . '/preview' );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status(), 'Guests are refused.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), 'Editors are refused as well.' );

		$this->admin();
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'private, no-store', $response->get_headers() );

		$missing = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/sw-postlist/v1/views/999999/preview' ) );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'view_not_found', $missing->get_data()['error'] );

		$bad_key = new WP_REST_Request( 'GET', '/sw-postlist/v1/views/' . $draft . '/preview' );
		$bad_key->set_param( 'key', 'Bad Key!' );
		$this->assertSame( 400, rest_get_server()->dispatch( $bad_key )->get_status() );
	}

	public function test_drafts_and_trashed_views_are_not_rendered_publicly(): void {
		$this->create_posts( 2 );
		$draft = $this->acceptance_view( array( 'query' => array( 'offset' => 0, 'taxonomies' => array() ) ), 'draft' );

		$html = do_shortcode( '[sw_postlist id="' . $draft . '"]' );
		$this->assertStringNotContainsString( 'swp-item', $html );
		$this->assertStringContainsString( 'currently unavailable', $html );
		$this->assertStringNotContainsString( 'view_not_found', $html, 'Visitors never see error codes.' );

		$this->admin();
		InstanceRegistry::reset();
		$this->assertStringContainsString( 'view_not_found', do_shortcode( '[sw_postlist id="' . $draft . '"]' ), 'Managers see the code of a failure.' );
	}

	public function test_missing_ids_and_garbage_attributes_render_nothing(): void {
		$this->assertSame( '', do_shortcode( '[sw_postlist]' ) );
		$this->assertSame( '', do_shortcode( '[sw_postlist id="abc"]' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:sw-postlist/view /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:sw-postlist/view {"viewId":0} /-->' ) );
		$this->assertStringContainsString( 'currently unavailable', do_shortcode( '[sw_postlist id="999999"]' ) );
	}

	public function test_two_identical_views_on_an_archive_page_independently_without_javascript(): void {
		$this->seed_acceptance_content();
		$view_id = $this->acceptance_view( array( 'query' => array( 'offset' => 0 ) ) );
		$this->go_to( get_category_link( $this->term ) );
		$first_key  = 'view-' . $view_id . '-1';
		$second_key = 'view-' . $view_id . '-2';

		$_GET = array( 'cat' => (string) $this->term, 'swp' => array( $second_key => array( 'page' => '3' ) ) );
		$first  = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
		$second = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );

		$this->assertStringContainsString( 'Published 1', $first, 'The first embedding stays on page 1.' );
		$this->assertStringContainsString( 'Published 5', $second, 'The second embedding shows page 3 from the direct link.' );
		$this->assertStringContainsString( 'id="swp-' . $first_key . '"', $first );
		$this->assertStringContainsString( 'id="swp-' . $second_key . '"', $second );

		// Links of the first embedding keep the state of the second and every other parameter.
		preg_match_all( '/href="([^"]+)"/', $first, $matches );
		$links = array_map( 'html_entity_decode', $matches[1] );
		$page2 = array_values( array_filter( $links, static fn ( string $link ): bool => str_contains( $link, rawurlencode( $first_key ) ) && str_contains( $link, 'page%5D=2' ) ) );
		$this->assertNotEmpty( $page2 );
		parse_str( ltrim( $page2[0], '?' ), $parsed );
		$this->assertSame( (string) $this->term, $parsed['cat'] );
		$this->assertSame( '3', $parsed['swp'][ $second_key ]['page'] );
		$this->assertSame( '2', $parsed['swp'][ $first_key ]['page'] );

		// Links of the second embedding drop the page parameter for page 1 and keep the first's state.
		preg_match_all( '/href="([^"]+)"/', $second, $matches );
		$links = array_map( 'html_entity_decode', $matches[1] );
		$to_first = array_values( array_filter( $links, static fn ( string $link ): bool => ! str_contains( $link, $second_key ) ) );
		$this->assertNotEmpty( $to_first, 'The link to page 1 of the second embedding has no state for it.' );
	}

	public function test_instance_keys_are_stable_explicit_or_derived(): void {
		$this->assertSame( 'view-7-1', InstanceRegistry::key_for( 7 ) );
		$this->assertSame( 'view-7-2', InstanceRegistry::key_for( 7 ) );
		$this->assertSame( 'news', InstanceRegistry::key_for( 7, 'news' ) );
		$this->assertSame( 'view-7-4', InstanceRegistry::key_for( 7, 'Bad Key!' ), 'An invalid explicit key falls back to the derived one.' );
		$this->assertSame( 'view-8-1', InstanceRegistry::key_for( 8 ) );
		InstanceRegistry::reset();
		$this->assertSame( 'view-7-1', InstanceRegistry::key_for( 7 ), 'A new request derives the same keys again.' );

		$this->assertSame( 1, InstanceRegistry::page_from( array(), 'a' ) );
		foreach ( array( '0', '-3', 'x', '1.5', '', '99999999', array( '2' ) ) as $bad ) {
			$this->assertSame( 1, InstanceRegistry::page_from( array( 'swp' => array( 'a' => array( 'page' => $bad ) ) ), 'a' ), wp_json_encode( $bad ) );
		}
		$this->assertSame( 4, InstanceRegistry::page_from( array( 'swp' => array( 'a' => array( 'page' => '4' ) ) ), 'a' ) );
		$this->assertSame( InstanceRegistry::MAX_PAGE, InstanceRegistry::page_from( array( 'swp' => array( 'a' => array( 'page' => '10000' ) ) ), 'a' ) );
		$this->assertSame( 1, InstanceRegistry::page_from( array( 'swp' => 'junk' ), 'a' ) );
	}

	public function test_query_strings_keep_foreign_state_and_drop_page_one(): void {
		$query = array( 'p' => '5', 'swp' => array( 'a' => array( 'page' => '3' ), 'b' => array( 'page' => '2', 'f' => array( 'cat' => '1' ) ) ) );

		$this->assertSame( '?p=5&swp%5Ba%5D%5Bpage%5D=4&swp%5Bb%5D%5Bpage%5D=2&swp%5Bb%5D%5Bf%5D%5Bcat%5D=1', InstanceRegistry::query_for_page( $query, 'a', 4 ) );
		$this->assertSame( '?p=5&swp%5Bb%5D%5Bpage%5D=2&swp%5Bb%5D%5Bf%5D%5Bcat%5D=1', InstanceRegistry::query_for_page( $query, 'a', 1 ) );
		$this->assertSame( '?', InstanceRegistry::query_for_page( array( 'swp' => array( 'a' => array( 'page' => '3' ) ) ), 'a', 1 ) );
		$this->assertSame( '?swp%5Bz%5D%5Bpage%5D=2', InstanceRegistry::query_for_page( array(), 'z', 2 ) );
	}

	public function test_pagination_windows_and_states(): void {
		$pagination = new \SW\Postlist\Infrastructure\WordPress\Render\PaginationRenderer();

		$this->assertSame( '', $pagination->render( 'k', 1, 5, 10, false, array() ), 'A single page needs no navigation.' );
		$middle = $pagination->render( 'k', 10, 200, 10, true, array() );
		$this->assertStringContainsString( 'aria-current="page"', $middle );
		$this->assertStringContainsString( 'rel="prev"', $middle );
		$this->assertStringContainsString( 'rel="next"', $middle );
		$this->assertSame( 2, substr_count( $middle, 'swp-pagination__gap' ), 'Gaps on both sides of the window.' );
		$this->assertStringContainsString( 'aria-label="Page 20"', $middle );
		$first = $pagination->render( 'k', 1, 30, 10, true, array() );
		$this->assertStringNotContainsString( 'rel="prev"', $first );
		$last = $pagination->render( 'k', 3, 30, 10, false, array() );
		$this->assertStringNotContainsString( 'rel="next"', $last );

		$this->assertSame( '', $pagination->render( 'k', 1, null, 10, false, array() ), 'A continuation source on page 1 without more entries has no navigation.' );
		$steps = $pagination->render( 'k', 2, null, 10, true, array() );
		$this->assertStringContainsString( 'rel="prev"', $steps );
		$this->assertStringContainsString( 'rel="next"', $steps );
		$this->assertStringNotContainsString( 'aria-current', $steps, 'No page numbers without an exact total.' );
		$this->assertStringNotContainsString( 'rel="next"', $pagination->render( 'k', 2, null, 10, false, array() ) );
	}

	public function test_no_pagination_mode_and_overrides_are_validated_by_the_same_schema(): void {
		$this->create_posts( 7 );
		$view_id = $this->services->views()->save( null, $this->view_document( array( 'query' => array( 'per_page' => 3 ), 'pagination' => array( 'mode' => 'classic' ) ) ) );

		$default = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
		$this->assertSame( 3, $this->items( $default ) );
		$this->assertStringContainsString( 'swp-pagination', $default );

		InstanceRegistry::reset();
		$more = do_shortcode( '[sw_postlist id="' . $view_id . '" per_page="5" layout="list" columns="4,3,2" orderby="title" order="desc" pagination="none"]' );
		$this->assertSame( 5, $this->items( $more ), 'Overrides apply.' );
		$this->assertStringContainsString( 'swp-layout-list', $more );
		$this->assertStringNotContainsString( 'swp-pagination', $more, 'Pagination mode none renders no navigation.' );

		$_GET = array( 'swp' => array( 'view-' . $view_id . '-1' => array( 'page' => '2' ) ) );
		InstanceRegistry::reset();
		$first_page_only = do_shortcode( '[sw_postlist id="' . $view_id . '" pagination="none"]' );
		$this->assertStringContainsString( 'Entry 1', $first_page_only, 'Without pagination a page parameter is ignored.' );
		$_GET = array();

		foreach ( array( 'per_page="0"', 'per_page="500"', 'layout="carousel"', 'columns="9"', 'orderby="rand"', 'order="sideways"', 'pagination="magic"' ) as $bad ) {
			InstanceRegistry::reset();
			$html = do_shortcode( '[sw_postlist id="' . $view_id . '" ' . $bad . ']' );
			$this->assertStringNotContainsString( 'swp-item', $html, $bad . ' is rejected by the schema.' );
			$this->assertStringContainsString( 'currently unavailable', $html );
		}
	}

	public function test_only_released_overrides_are_accepted(): void {
		$this->create_posts( 2 );
		$view_id  = $this->services->views()->save( null, $this->view_document() );
		$renderer = $this->services->renderer();

		$ok = $renderer->render( $view_id, new RenderRequest( 'k', 1, null, null, array( 'query' => array( 'per_page' => 1 ) ) ) );
		$this->assertTrue( $ok->is_success() );
		$this->assertSame( 1, $ok->count );

		foreach ( array(
			array( 'source' => array( 'x' => 'post:page' ) ),
			array( 'query' => array( 'include' => array( 1 ) ) ),
			array( 'query' => array( 'taxonomies' => array() ) ),
			array( 'templates' => array( 'entry' => 5 ) ),
			array( 'layout' => 'grid' ),
			array( 'query' => array( 'context' => 'archive' ) ),
		) as $overrides ) {
			$result = $renderer->render( $view_id, new RenderRequest( 'k', 1, null, null, $overrides ) );
			$this->assertFalse( $result->is_success(), wp_json_encode( $overrides ) );
			$this->assertSame( 'override_not_allowed', $result->error_code );
		}
		$invalid = $renderer->render( $view_id, new RenderRequest( 'k', 1, null, null, array( 'layout' => array( 'columns' => array( 'desktop' => 99 ) ) ) ) );
		$this->assertSame( 'override_invalid', $invalid->error_code );
	}

	public function test_layout_options_become_validated_css_variables_and_classes(): void {
		$this->create_posts( 2 );
		$view_id = $this->services->views()->save(
			null,
			$this->view_document(
				array(
					'layout' => array(
						'type'         => 'grid',
						'columns'      => array( 'desktop' => 4, 'tablet' => 3 ),
						'gap'          => array( 'desktop' => 30 ),
						'font_size'    => array( 'mobile' => 90 ),
						'aspect_ratio' => '16/9',
						'accent'       => 'vivid-red',
						'hidden'       => array( 'mobile' => true, 'tablet' => false ),
					),
				)
			)
		);

		$html = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );

		foreach ( array( '--swp-cols-desktop:4;', '--swp-cols-tablet:3;', '--swp-cols-mobile:1;', '--swp-gap-desktop:30px;', '--swp-gap-tablet:16px;', '--swp-font-mobile:90%;', '--swp-font-desktop:100%;', '--swp-aspect:16 / 9;', '--swp-accent:var(--wp--preset--color--vivid-red, #2271b1);' ) as $declaration ) {
			$this->assertStringContainsString( $declaration, $html );
		}
		$this->assertStringContainsString( 'swp-hide-mobile', $html );
		$this->assertStringNotContainsString( 'swp-hide-tablet', $html );
		$this->assertStringNotContainsString( 'swp-hide-desktop', $html );
		$this->assertTrue( wp_style_is( 'sw-postlist-frontend', 'enqueued' ), 'The stylesheet loads only when a view renders.' );
		$this->assertSame( SW_POSTLIST_VERSION, wp_styles()->registered['sw-postlist-frontend']->ver );
		$this->assertFileExists( SW_POSTLIST_DIR . 'assets/css/frontend.css' );
	}

	public function test_layout_style_ignores_unsafe_values_defensively(): void {
		$style = \SW\Postlist\Infrastructure\WordPress\Render\LayoutStyle::for_layout(
			array(
				'type'         => 'gr id"><script>',
				'columns'      => array( 'desktop' => '9; background:url(x)', 'tablet' => 2 ),
				'aspect_ratio' => '16/9;x',
				'accent'       => 'a;b',
				'hidden'       => array( 'desktop' => true, 'watch' => true ),
			)
		);

		$this->assertSame( array( 'swp-layout-gridscript', 'swp-hide-desktop' ), $style['classes'] );
		$this->assertStringContainsString( '--swp-cols-desktop:3;', $style['style'], 'A non-integer value falls back to the default.' );
		$this->assertStringContainsString( '--swp-cols-tablet:2;', $style['style'] );
		$this->assertStringNotContainsString( 'aspect', $style['style'] );
		$this->assertStringNotContainsString( 'accent', $style['style'] );
		$this->assertStringNotContainsString( ';background', $style['style'] );
	}

	public function test_custom_templates_apply_and_their_css_is_emitted_once(): void {
		$posts     = $this->create_posts( 3 );
		$container = $this->services->templates()->save( null, $this->template_document( '<section class="mine">{{view.title}}{{view.entries}}</section>', TemplateRole::Container, 'C' ) );
		$entry     = $this->services->templates()->save( null, new \SW\Postlist\Domain\Template\TemplateDocument( 'E', TemplateRole::Entry, '<p class="e">{{entry.title}}</p>', '.e{color:red}' ) );
		$view_id   = $this->services->views()->save( null, $this->view_document( array( 'title' => 'Custom <b>view</b>', 'templates' => array( 'container' => $container, 'entry' => $entry ) ) ) );

		$first  = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
		$second = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );

		$this->assertStringContainsString( '<section class="mine">Custom &lt;b&gt;view&lt;/b&gt;', $first );
		$this->assertStringContainsString( '<p class="e">Entry 1</p>', $first );
		$this->assertStringContainsString( 'swp-tpl-' . $entry . ' ', $first );
		$this->assertStringContainsString( '<style id="swp-css-swp-tpl-' . $entry . '">.swp-tpl-' . $entry . ' .e{color:red}', $first );
		$this->assertSame( 1, substr_count( $first . $second, '<style id="swp-css-swp-tpl-' . $entry . '">' ), 'The CSS goes out once per page.' );
		$this->assertSame( 3, $this->items( $first ) );
		$this->assertCount( 3, $posts );
		$this->assertStringContainsString( 'swp-pagination', $first . $second . '<nav class="swp-pagination">', 'Navigation placement is optional.' );
	}

	public function test_a_container_without_entries_placeholder_gets_entries_and_navigation_appended(): void {
		$this->create_posts( 4 );
		$container = $this->services->templates()->save( null, $this->template_document( '<header>{{view.title}}</header>', TemplateRole::Container, 'C' ) );
		$view_id   = $this->services->views()->save( null, $this->view_document( array( 'query' => array( 'per_page' => 2 ), 'templates' => array( 'container' => $container ) ) ) );

		$html = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );

		$this->assertSame( 2, $this->items( $html ) );
		$this->assertLessThan( strpos( $html, 'swp-item' ), strpos( $html, '<header>' ) );
		$this->assertGreaterThan( strpos( $html, 'swp-item' ), strpos( $html, 'swp-pagination' ) );
	}

	public function test_invalid_or_vanished_templates_fall_back_safely(): void {
		$this->create_posts( 2 );
		$template = $this->services->templates()->save( null, $this->template_document( '<p>{{entry.title}}</p>' ) );
		$view_id  = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $template ) ) ) );

		// A template whose stored document is not valid renders through the built-in one.
		update_post_meta( $template, \SW\Postlist\Infrastructure\WordPress\PostTypes::META_DOCUMENT, 'broken' );
		$html = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
		$this->assertStringContainsString( 'swp-card', $html );
		$this->assertSame( 2, $this->items( $html ) );

		// A template that no longer compiles (formatter removed) is a neutral error.
		$broken = $this->services->templates()->save( null, $this->template_document( '<p>{{entry.title|nope}}</p>' ) );
		$other  = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $broken ) ) ) );
		InstanceRegistry::reset();
		$failed = do_shortcode( '[sw_postlist id="' . $other . '"]' );
		$this->assertStringNotContainsString( 'swp-item', $failed );
		$this->assertStringContainsString( 'currently unavailable', $failed );
		$logged = \SW\Postlist\Infrastructure\WordPress\WpLogger::entries();
		$this->assertContains( 'template_invalid', array_column( $logged, 'code' ) );
	}

	public function test_missing_source_is_a_neutral_error(): void {
		$this->create_posts( 1 );
		$view_id = $this->services->views()->save( null, $this->view_document( array( 'source' => 'post:gone' ) ) );

		$this->assertSame( 'source_missing', $this->services->renderer()->render( $view_id, new RenderRequest( 'k' ) )->error_code );
	}

	public function test_hooks_around_rendering(): void {
		$this->create_posts( 2 );
		$view_id = $this->services->views()->save( null, $this->view_document() );
		$events  = array();
		add_action(
			'sw_postlist_before_render',
			static function ( int $id, RenderRequest $request ) use ( &$events ): void {
				$events[] = 'before:' . $id . ':' . $request->instance_key;
			},
			10,
			2
		);
		add_filter(
			'sw_postlist_render_html',
			static function ( string $html, int $id, $result ) use ( &$events ): string {
				$events[] = 'html:' . $result->count;
				return '<!--wrapped-->' . $html;
			},
			10,
			3
		);
		add_action(
			'sw_postlist_after_render',
			static function ( int $id, $result ) use ( &$events ): void {
				$events[] = 'after:' . ( $result->is_success() ? 'ok' : $result->error_code );
			},
			10,
			2
		);

		$html = do_shortcode( '[sw_postlist id="' . $view_id . '" key="hooked"]' );

		$this->assertStringStartsWith( '<!--wrapped-->', $html );
		$this->assertSame( array( 'before:' . $view_id . ':hooked', 'html:2', 'after:ok' ), $events );

		remove_all_filters( 'sw_postlist_render_html' );
		add_filter( 'sw_postlist_render_html', static fn () => array( 'not a string' ) );
		InstanceRegistry::reset();
		$this->assertStringContainsString( 'swp-view', do_shortcode( '[sw_postlist id="' . $view_id . '"]' ), 'A non-string filter result is ignored.' );
	}

	public function test_grid_card_uses_a_responsive_image(): void {
		$post_id       = self::factory()->post->create( array( 'post_title' => 'With image' ) );
		$attachment_id = self::factory()->attachment->create_upload_object( dirname( __DIR__, 2 ) . '/Fixtures/pixel.png', $post_id );
		if ( 0 === $attachment_id ) {
			$this->markTestSkipped( 'Image upload is not available.' );
		}
		set_post_thumbnail( $post_id, $attachment_id );
		$view_id = $this->services->views()->save( null, $this->view_document() );

		$html = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );

		$this->assertStringContainsString( 'class="swp-card__media"', $html );
		$this->assertStringContainsString( '<img ', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'decoding="async"', $html );
	}

	public function test_context_origin_is_read_signed_and_verified(): void {
		$cat_a  = self::factory()->category->create( array( 'name' => 'CatA' ) );
		$cat_b  = self::factory()->category->create( array( 'name' => 'CatB' ) );
		$origin = self::factory()->post->create( array( 'post_category' => array( $cat_a ), 'post_title' => 'The origin' ) );
		$same   = self::factory()->post->create( array( 'post_category' => array( $cat_a ), 'post_title' => 'Same category' ) );
		self::factory()->post->create( array( 'post_category' => array( $cat_b ), 'post_title' => 'Other category' ) );
		$view_id = $this->services->views()->save( null, $this->view_document( array( 'query' => array( 'context' => 'same_category' ) ) ) );

		$this->go_to( get_permalink( $origin ) );
		$html = do_shortcode( '[sw_postlist id="' . $view_id . '" key="related"]' );

		$this->assertStringContainsString( 'Same category', $html );
		$this->assertStringNotContainsString( 'Other category', $html );
		$this->assertStringNotContainsString( 'The origin', $html );
		$this->assertSame( 1, preg_match( '/data-swp-token="([^"]+)"/', $html, $match ) );

		$signer = new OriginSigner();
		$origin_value = $signer->verify( $match[1], $view_id, 'related' );
		$this->assertSame( array( ContextOrigin::MODE_POST, $origin ), array( $origin_value->mode, $origin_value->id ) );
		$this->assertNull( $signer->verify( $match[1], $view_id + 1, 'related' ), 'The token is bound to the view.' );
		$this->assertNull( $signer->verify( $match[1], $view_id, 'other' ), 'The token is bound to the instance.' );
		$this->assertSame( $same, get_post( $same )->ID );

		InstanceRegistry::reset();
		$plain = $this->services->views()->save( null, $this->view_document() );
		$this->assertStringNotContainsString( 'data-swp-token', do_shortcode( '[sw_postlist id="' . $plain . '"]' ), 'Static views without context carry no token.' );
	}

	public function test_origin_tokens_resist_tampering_and_cover_every_mode(): void {
		$signer = new OriginSigner();
		foreach ( array(
			ContextOrigin::post( 12 ),
			ContextOrigin::term( 'category', 5 ),
			ContextOrigin::author( 3 ),
			ContextOrigin::search( 'needle ä' ),
		) as $origin ) {
			$token    = $signer->sign( 9, 'k', $origin );
			$verified = $signer->verify( $token, 9, 'k' );
			$this->assertEquals( $origin, $verified );
		}
		$token = $signer->sign( 9, 'k', ContextOrigin::post( 12 ) );
		list( $payload, $signature ) = explode( '.', $token );

		$forged_payload = rtrim( strtr( base64_encode( (string) wp_json_encode( array( 'v' => 1, 'view' => 9, 'key' => 'k', 'm' => 'post', 'i' => 99, 't' => null, 's' => null ) ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test forgery.
		foreach ( array(
			$forged_payload . '.' . $signature,
			$payload . '.' . strrev( $signature ),
			$payload,
			$payload . '.' . $signature . '.x',
			'',
			'....',
			str_repeat( 'a', 1100 ),
			$payload . '.',
		) as $bad ) {
			$this->assertNull( $signer->verify( $bad, 9, 'k' ), substr( $bad, 0, 40 ) );
		}
		// A correctly signed token with unusable content is rejected as well.
		foreach ( array(
			array( 'v' => 2, 'view' => 9, 'key' => 'k', 'm' => 'post', 'i' => 1 ),
			array( 'v' => 1, 'view' => 9, 'key' => 'k', 'm' => 'post', 'i' => -4 ),
			array( 'v' => 1, 'view' => 9, 'key' => 'k', 'm' => 'term', 'i' => 4, 't' => 'Bad Tax!' ),
			array( 'v' => 1, 'view' => 9, 'key' => 'k', 'm' => 'search', 's' => 5 ),
			array( 'v' => 1, 'view' => 9, 'key' => 'k', 'm' => 'galaxy', 'i' => 1 ),
		) as $content ) {
			$encoded = rtrim( strtr( base64_encode( (string) wp_json_encode( $content ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test forgery.
			$signed  = $encoded . '.' . rtrim( strtr( base64_encode( hash_hmac( 'sha256', $encoded, wp_salt( 'sw_postlist_origin' ), true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test forgery.
			$this->assertNull( $signer->verify( $signed, 9, 'k' ), wp_json_encode( $content ) );
		}
	}

	public function test_context_view_without_an_origin_shows_an_empty_state(): void {
		$this->create_posts( 3 );
		$view_id = $this->services->views()->save( null, $this->view_document( array( 'query' => array( 'context' => 'same_category' ) ) ) );

		$this->go_to( home_url( '/' ) );
		$html = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );

		$this->assertStringContainsString( 'No entries found.', $html );
		$this->assertSame( 0, $this->items( $html ) );
	}

	public function test_views_endpoint_lists_published_views_for_editors_only(): void {
		$published = $this->services->views()->save( null, $this->view_document( array( 'title' => 'Shown' ) ) );
		$this->services->views()->save( null, $this->view_document( array( 'title' => 'Hidden draft' ) ), 'draft' );

		$request = new WP_REST_Request( 'GET', '/sw-postlist/v1/views' );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( array( array( 'id' => $published, 'title' => 'Shown' ) ), $data );
	}

	public function test_block_json_and_editor_script_exist_without_a_build_step(): void {
		$json = json_decode( (string) file_get_contents( SW_POSTLIST_DIR . 'blocks/view/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin file.

		$this->assertSame( ViewBlock::NAME, $json['name'] );
		$this->assertSame( 3, $json['apiVersion'] );
		$this->assertFileExists( SW_POSTLIST_DIR . 'blocks/view/index.js' );
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( ViewBlock::NAME ) );
		$this->assertSame( ViewsController::NAMESPACE, 'sw-postlist/v1' );
	}
}
