<?php
/**
 * M7 acceptance: the public reload route serves exactly what the page shows for the same URL state, and nothing else.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\EntryResult;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\OriginSigner;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;
use SW\Postlist\Services;

class PublicRestTest extends SWP_TestCase {

	private int $term;

	/** @var list<int> */
	private array $published = array();

	public function set_up(): void {
		parent::set_up();
		$this->reset_rendering();
		$_GET       = array();
		$this->term = self::factory()->category->create( array( 'name' => 'Match' ) );
		$base       = array( 'post_category' => array( $this->term ) );
		foreach ( range( 1, 6 ) as $number ) {
			$this->published[] = self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Published ' . $number, 'post_content' => 'Plain body', 'post_excerpt' => 'Short', 'post_date' => '2026-01-0' . $number . ' 10:00:00' ) ) );
		}
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Draft entry', 'post_status' => 'draft' ) ) );
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Private entry', 'post_status' => 'private' ) ) );
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Protected entry', 'post_password' => 'secret' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		InstanceRegistry::reset();
		ViewRenderer::reset();
		parent::tear_down();
	}

	private function reset_rendering(): void {
		InstanceRegistry::reset();
		ViewRenderer::reset();
		( new PostlistShortcode( $this->services->renderer() ) )->register();
	}

	/**
	 * @param array<string, mixed> $overrides Overrides of the acceptance view.
	 */
	private function view( array $overrides = array(), string $status = 'publish' ): int {
		$document = $this->view_document(
			array_replace_recursive(
				array(
					'title'      => 'Acceptance',
					'query'      => array(
						'per_page'   => 2,
						'offset'     => 1,
						'orderby'    => 'date',
						'order'      => 'asc',
						'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => array( $this->term ), 'operator' => 'in' ) ),
					),
					'pagination' => array( 'mode' => 'ajax' ),
				),
				$overrides
			)
		);
		return $this->services->views()->save( null, $document, $status );
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 * @return array<string, mixed>
	 */
	private function fetch( int $view_id, array $params = array() ): array {
		global $wp_rest_server;
		$wp_rest_server = null;
		$request        = new WP_REST_Request( 'GET', '/sw-postlist/v1/views/' . $view_id . '/render' );
		foreach ( array_merge( array( 'key' => 'view-' . $view_id . '-1' ), $params ) as $name => $value ) {
			$request->set_param( $name, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		$data     = (array) $response->get_data();
		$data['_status']  = $response->get_status();
		$data['_headers'] = $response->get_headers();
		return $data;
	}

	/**
	 * @return list<string>
	 */
	private function titles( string $html ): array {
		preg_match_all( '/swp-card__title[^>]*>(?:<a[^>]*>)?([^<]+)/', $html, $found );
		return array_map( 'html_entity_decode', $found[1] );
	}

	private function page_html( int $view_id, string $query = '' ): string {
		parse_str( ltrim( $query, '?' ), $_GET );
		$this->reset_rendering();
		return do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
	}

	public function test_acceptance_count_case_matches_html_and_rest(): void {
		$view = $this->view();
		$key  = 'view-' . $view . '-1';
		foreach ( array(
			1 => array( 'Published 2', 'Published 3' ),
			2 => array( 'Published 4', 'Published 5' ),
			3 => array( 'Published 6' ),
		) as $page => $expected ) {
			$query = 1 === $page ? '' : 'swp[' . $key . '][page]=' . $page;
			$rest  = $this->fetch( $view, array( 'query' => $query ) );
			$this->assertSame( 200, $rest['_status'] );
			$this->assertSame( $expected, $this->titles( $rest['html'] ), 'REST page ' . $page );
			$this->assertSame( 5, $rest['total'], 'Six visible entries minus the start offset.' );
			$this->assertSame( $page < 3, $rest['has_more'] );
			$this->assertSame( $page, $rest['page'] );
			$this->assertSame( count( $expected ), $rest['count'] );
			$this->assertSame( $this->titles( $this->page_html( $view, $query ) ), $this->titles( $rest['html'] ), 'HTML and REST list the same entries.' );
		}
		foreach ( array( 'Draft entry', 'Private entry', 'Protected entry' ) as $hidden ) {
			$this->assertStringNotContainsString( $hidden, wp_json_encode( $this->fetch( $view, array( 'query' => 'swp[' . $key . '][page]=2' ) ) ) );
		}
	}

	public function test_filters_in_the_query_apply_identically_on_page_and_route(): void {
		$view  = $this->view( array( 'query' => array( 'offset' => 0, 'per_page' => 10 ), 'filters' => array( array( 'type' => 'search' ), array( 'type' => 'sort', 'options' => array( 'title' ) ) ) ) );
		$key   = 'view-' . $view . '-1';
		$query = 'swp[' . $key . '][f][q]=Published%204';
		$rest  = $this->fetch( $view, array( 'query' => $query ) );
		$this->assertSame( array( 'Published 4' ), $this->titles( $rest['html'] ) );
		$this->assertSame( 1, $rest['total'] );
		$this->assertSame( $this->titles( $this->page_html( $view, $query ) ), $this->titles( $rest['html'] ) );
		$this->assertSame( array(), $this->titles( $this->fetch( $view, array( 'query' => 'swp[' . $key . '][f][q]=Draft' ) )['html'] ), 'Hidden entries cannot be searched.' );
		$sorted = $this->titles( $this->fetch( $view, array( 'query' => 'swp[' . $key . '][f][sort]=title:desc' ) )['html'] );
		$this->assertSame( 'Published 6', $sorted[0] );
		$this->assertContains( 'swp-filters', array( 'swp-filters' ), 'The route also returns the form for the new state.' );
		$this->assertStringContainsString( 'swp-filters', $rest['html'] );
		$this->assertStringContainsString( 'name="swp[' . $key . '][f][q]" value="Published 4"', $rest['html'] );
	}

	public function test_a_signed_token_carries_the_overrides_of_the_embedding(): void {
		$view = $this->view( array( 'query' => array( 'offset' => 0 ) ) );
		$html = $this->page_html( $view );
		$this->assertSame( 1, preg_match( '/data-swp-token="([^"]+)"/', $html, $match ) );
		$this->assertStringContainsString( 'data-swp-mode="ajax"', $html );
		$this->assertCount( 2, $this->titles( $html ) );

		$_GET = array();
		$this->reset_rendering();
		$with_override = do_shortcode( '[sw_postlist id="' . $view . '" per_page="3"]' );
		preg_match( '/data-swp-token="([^"]+)"/', $with_override, $override_token );
		$this->assertCount( 3, $this->titles( $with_override ) );

		$this->assertCount( 3, $this->titles( $this->fetch( $view, array( 'token' => $override_token[1] ) )['html'] ), 'The token restores the per_page override.' );
		$this->assertCount( 2, $this->titles( $this->fetch( $view, array( 'token' => $match[1] ) )['html'] ) );
		$this->assertCount( 2, $this->titles( $this->fetch( $view )['html'] ), 'No token: the stored view as published.' );

		$parts  = explode( '.', $override_token[1] );
		$forged = $parts[0] . '.' . strrev( $parts[1] );
		$this->assertCount( 2, $this->titles( $this->fetch( $view, array( 'token' => $forged ) )['html'] ), 'A forged token is ignored.' );
		$other = $this->view( array( 'query' => array( 'offset' => 0 ) ) );
		$this->assertCount( 2, $this->titles( $this->fetch( $other, array( 'token' => $override_token[1] ) )['html'] ), 'A token is bound to its view.' );
		$this->assertCount( 2, $this->titles( $this->fetch( $view, array( 'token' => $override_token[1], 'key' => 'another' ) )['html'] ), 'A token is bound to its instance.' );
	}

	public function test_unpublished_missing_and_invalid_requests_end_neutral(): void {
		$draft = $this->view( array(), 'draft' );
		$trash = $this->view();
		wp_trash_post( $trash );
		foreach ( array( $draft, $trash, 999999 ) as $id ) {
			$result = $this->fetch( $id );
			$this->assertSame( 404, $result['_status'] );
			$this->assertSame( 'view_not_found', $result['error'] );
			$this->assertStringNotContainsString( 'swp-item', $result['html'] ?? '' );
			$this->assertStringContainsString( 'currently unavailable', $result['html'] ?? '' );
		}
		$this->admin();
		$this->assertSame( 404, $this->fetch( $draft )['_status'], 'Even managers see no drafts on the public route (the editor preview exists for that).' );
		wp_set_current_user( 0 );

		$view = $this->view();
		$this->assertSame( 400, $this->fetch( $view, array( 'key' => 'Bad Key!' ) )['_status'] );
		$this->assertSame( 400, $this->fetch( $view, array( 'query' => str_repeat( 'a', 2001 ) ) )['_status'] );
		$this->assertSame( 400, $this->fetch( $view, array( 'token' => str_repeat( 'a', 1001 ) ) )['_status'] );
	}

	public function test_content_withdrawn_after_the_first_render_disappears_from_the_route(): void {
		$view = $this->view( array( 'query' => array( 'offset' => 0, 'per_page' => 10 ) ) );
		$this->assertContains( 'Published 3', $this->titles( $this->fetch( $view )['html'] ) );
		wp_update_post( array( 'ID' => $this->published[2], 'post_status' => 'draft' ) );
		$result = $this->fetch( $view );
		$this->assertNotContains( 'Published 3', $this->titles( $result['html'] ) );
		$this->assertSame( 5, $result['total'] );
		wp_update_post( array( 'ID' => $this->published[3], 'post_password' => 'now-protected' ) );
		$this->assertNotContains( 'Published 4', $this->titles( $this->fetch( $view )['html'] ) );
		wp_update_post( array( 'ID' => $this->published[4], 'post_status' => 'private' ) );
		$this->assertSame( 3, $this->fetch( $view )['total'] );
	}

	public function test_signed_in_users_get_the_same_public_selection(): void {
		$view      = $this->view( array( 'query' => array( 'offset' => 0, 'per_page' => 10 ) ) );
		$anonymous = $this->titles( $this->fetch( $view )['html'] );
		$this->admin();
		$this->assertSame( $anonymous, $this->titles( $this->fetch( $view )['html'] ) );
		$this->assertNotContains( 'Draft entry', $anonymous );
	}

	public function test_context_views_use_the_signed_origin_and_check_its_visibility_again(): void {
		$origin = self::factory()->post->create( array( 'post_category' => array( $this->term ), 'post_title' => 'The origin' ) );
		$view   = $this->view( array( 'query' => array( 'context' => 'same_category', 'offset' => 0, 'per_page' => 10, 'taxonomies' => array() ), 'pagination' => array( 'mode' => 'ajax' ) ) );
		$this->go_to( get_permalink( $origin ) );
		$html = do_shortcode( '[sw_postlist id="' . $view . '" key="related"]' );
		preg_match( '/data-swp-token="([^"]+)"/', $html, $token );
		$this->assertStringContainsString( 'Published 1', $html );

		$rest = $this->fetch( $view, array( 'key' => 'related', 'token' => $token[1] ) );
		$this->assertContains( 'Published 1', $this->titles( $rest['html'] ) );
		$this->assertNotContains( 'The origin', $this->titles( $rest['html'] ) );

		$this->assertSame( array(), $this->titles( $this->fetch( $view, array( 'key' => 'related' ) )['html'] ), 'Without a verified origin the context view is empty.' );
		$this->assertSame( array(), $this->titles( $this->fetch( $view, array( 'key' => 'related', 'token' => substr( $token[1], 0, -2 ) . 'xx' ) )['html'] ) );

		wp_update_post( array( 'ID' => $origin, 'post_status' => 'draft' ) );
		$this->assertSame( array(), $this->titles( $this->fetch( $view, array( 'key' => 'related', 'token' => $token[1] ) )['html'] ), 'The origin was withdrawn after the first render.' );
	}

	public function test_a_source_without_total_gives_continuation_pagination(): void {
		$entries = array( new Entry( 1, 'ext:items', array( 'title' => 'First' ) ), new Entry( 2, 'ext:items', array( 'title' => 'Second' ) ) );
		add_filter(
			'sw_postlist_entry_sources',
			static function ( array $sources ) use ( $entries ): array {
				$sources[] = new \SW\Postlist\Tests\Support\FakeSource( 'ext:items', $entries, array(), array( 'taxonomies' => array(), 'orderby' => array( 'date' ), 'visitor_filters' => array( 'search' ), 'count_mode' => 'continuation' ), null, true );
				return $sources;
			}
		);
		Services::reset();
		$this->services = Services::instance();
		$this->admin();
		$entry_template = $this->services->templates()->save( null, $this->template_document( '<article class="swp-card__title">Item</article>' ), 'publish' );
		wp_set_current_user( 0 );
		$view = $this->services->views()->save( null, $this->view_document( array( 'source' => 'ext:items', 'query' => array( 'per_page' => 2 ), 'pagination' => array( 'mode' => 'load_more' ), 'filters' => array( array( 'type' => 'search' ) ), 'templates' => array( 'entry' => $entry_template ) ) ) );
		$rest           = $this->fetch( $view );
		$this->assertNull( $rest['error'], 'The view renders (a failed render would also have no total).' );
		$this->assertNull( $rest['total'] );
		$this->assertTrue( $rest['has_more'] );
		$this->assertSame( 2, $rest['count'] );
		$this->assertStringContainsString( 'rel="next"', $rest['html'] );
		$this->assertStringContainsString( 'swp-filters', $rest['html'], 'The source supports search.' );
		$this->assertStringNotContainsString( 'swp-pagination__link--prev', $rest['html'] );
	}

	public function test_the_route_is_public_cache_revalidating_and_changeable(): void {
		$view = $this->view();
		$this->assertSame( 'no-cache', $this->fetch( $view )['_headers']['Cache-Control'] );
		add_filter( 'sw_postlist_public_cache_control', static fn ( string $value ): string => 'public, max-age=30' );
		$this->assertSame( 'public, max-age=30', $this->fetch( $view )['_headers']['Cache-Control'] );
		$route = rest_get_server()->get_routes()['/sw-postlist/v1/views/(?P<id>\d+)/render'][0];
		$this->assertSame( '__return_true', $route['permission_callback'], 'Public by design, see class description.' );
		$this->assertStringNotContainsString( 'nonce', strtolower( (string) wp_json_encode( array_keys( $route['args'] ) ) ), 'No nonce: a nonce would only break page caches.' );
	}

	public function test_garbage_query_strings_never_break_the_route(): void {
		$view = $this->view( array( 'filters' => array( array( 'type' => 'search' ), array( 'type' => 'taxonomy', 'taxonomy' => 'category' ) ) ) );
		$key  = 'view-' . $view . '-1';
		foreach ( array(
			'swp=1',
			'swp[]=1&swp[][]=2',
			'swp[' . $key . '][f]=x',
			'swp[' . $key . '][f][tax_category][][][]=1',
			'swp[' . $key . '][page]=-5',
			'swp[' . $key . '][page]=99999999999999999999',
			'swp[' . $key . '][f][q]=%00%ff%fe',
			str_repeat( 'a[]=1&', 300 ),
			'%',
		) as $query ) {
			$result = $this->fetch( $view, array( 'query' => $query ) );
			$this->assertContains( $result['_status'], array( 200, 400 ), $query );
			$this->assertNull( $result['error'] ?? null, $query );
		}
	}

	public function test_signer_embed_state_roundtrip_and_rejection(): void {
		$signer = new OriginSigner();
		$token  = $signer->sign( 5, 'k', null, array( 'query' => array( 'per_page' => 4 ) ) );
		$state  = $signer->verify_embed( $token, 5, 'k' );
		$this->assertNull( $state['origin'] );
		$this->assertSame( array( 'query' => array( 'per_page' => 4 ) ), $state['overrides'] );
		$this->assertNull( $signer->verify( $token, 5, 'k' ), 'A token without origin has no origin.' );
		$this->assertNull( $signer->verify_embed( $token, 6, 'k' ) );
		$this->assertNull( $signer->verify_embed( 'garbage', 5, 'k' ) );
		$this->assertSame( array(), $signer->verify_embed( $signer->sign( 5, 'k', null ), 5, 'k' )['overrides'] );
	}
}
