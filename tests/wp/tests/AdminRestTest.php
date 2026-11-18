<?php
/**
 * M4 backend: private admin routes, shared template publication and access control.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Block\ViewBlock;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;

class AdminRestTest extends SWP_TestCase {

	public function set_up(): void {
		parent::set_up();
		InstanceRegistry::reset();
		ViewRenderer::reset();
		$_GET = array();
		global $wp_rest_server;
		$wp_rest_server = null;
		( new PostlistShortcode( $this->services->renderer() ) )->register();
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( ViewBlock::NAME ) ) {
			unregister_block_type( ViewBlock::NAME );
		}
		( new ViewBlock( $this->services->renderer() ) )->register();
		add_action( 'rest_api_init', array( \SW\Postlist\Plugin::instance(), 'register_rest' ) );
		$this->admin();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( ViewBlock::NAME ) ) {
			unregister_block_type( ViewBlock::NAME );
		}
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed>|null $body JSON body.
	 */
	private function call( string $method, string $route, ?array $body = null, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-postlist/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param array<string, mixed> $overrides Overrides of the raw document.
	 * @return array<string, mixed>
	 */
	private function raw_view( array $overrides = array() ): array {
		return $this->view_document( $overrides )->to_array();
	}

	public function test_every_route_requires_the_management_capability(): void {
		$template = $this->services->templates()->save( null, $this->template_document() );
		$view     = $this->services->views()->save( null, $this->view_document() );
		$routes   = array(
			array( 'GET', '/admin/views' ),
			array( 'POST', '/admin/views' ),
			array( 'GET', '/admin/views/' . $view ),
			array( 'PUT', '/admin/views/' . $view ),
			array( 'DELETE', '/admin/views/' . $view ),
			array( 'POST', '/admin/views/validate' ),
			array( 'POST', '/admin/views/preview' ),
			array( 'GET', '/admin/templates' ),
			array( 'POST', '/admin/templates' ),
			array( 'GET', '/admin/templates/' . $template ),
			array( 'PUT', '/admin/templates/' . $template ),
			array( 'DELETE', '/admin/templates/' . $template ),
			array( 'POST', '/admin/templates/validate' ),
			array( 'POST', '/admin/templates/preview' ),
			array( 'GET', '/admin/sources' ),
			array( 'GET', '/admin/placeholders' ),
			array( 'GET', '/admin/diagnostics' ),
			array( 'GET', '/admin/layouts' ),
			array( 'GET', '/admin/filter-types' ),
			array( 'POST', '/admin/export' ),
			array( 'POST', '/admin/import' ),
			array( 'POST', '/admin/views/' . $view . '/duplicate' ),
			array( 'POST', '/admin/templates/' . $template . '/duplicate' ),
			array( 'GET', '/admin/settings' ),
			array( 'PUT', '/admin/settings' ),
		);
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'editor' ) ), self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( $routes as list( $method, $route ) ) {
				$status = $this->call( $method, $route, array() )->get_status();
				$this->assertContains( $status, array( 401, 403 ), $method . ' ' . $route . ' as user ' . $user_id );
			}
		}
		$this->assertNotNull( $this->services->views()->find( $view ), 'Nothing was changed by the refused requests.' );
		$this->assertNotNull( $this->services->templates()->find( $template ) );
	}

	public function test_requests_without_a_rest_nonce_are_treated_as_guests(): void {
		$admin = $this->admin();
		$this->assertTrue( current_user_can( 'manage_options' ) );

		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		$GLOBALS['wp_rest_auth_cookie'] = true; // The session was authenticated by cookie, as in the admin app.
		rest_cookie_check_errors( null );
		$this->assertFalse( current_user_can( 'manage_options' ), 'Core drops the cookie session without a valid nonce, so no admin route is reachable (CSRF).' );

		wp_set_current_user( $admin );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$this->assertNotInstanceOf( WP_Error::class, rest_cookie_check_errors( null ) );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
	}

	public function test_view_crud_validation_and_listing(): void {
		$created = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view( array( 'title' => 'First' ) ), 'status' => 'publish' ) );
		$this->assertSame( 201, $created->get_status() );
		$view_id = $created->get_data()['id'];
		$this->assertSame( 'publish', $created->get_data()['status'] );

		$shown = $this->call( 'GET', '/admin/views/' . $view_id );
		$this->assertSame( 'First', $shown->get_data()['document']['title'] );
		$this->assertContains( 'private, no-store', $shown->get_headers() );

		$changed = $this->raw_view( array( 'title' => 'Renamed', 'query' => array( 'per_page' => 4 ) ) );
		$updated = $this->call( 'PUT', '/admin/views/' . $view_id, array( 'document' => $changed, 'status' => 'draft' ) );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'draft', $updated->get_data()['status'] );

		$list = $this->call( 'GET', '/admin/views' )->get_data()['views'];
		$this->assertSame( array( 'id' => $view_id, 'title' => 'Renamed', 'status' => 'draft', 'source' => 'post:post', 'layout' => 'grid' ), array_intersect_key( $list[0], array_flip( array( 'id', 'title', 'status', 'source', 'layout' ) ) ) );

		$invalid = $this->call( 'POST', '/admin/views', array( 'document' => array( 'schema_version' => 1, 'title' => '', 'source' => array( 'post:post', 'post:page' ) ) ) );
		$this->assertSame( 422, $invalid->get_status() );
		$codes = array_column( $invalid->get_data()['errors'], 'code' );
		$this->assertContains( 'title_invalid', $codes );
		$this->assertContains( 'source_multiple', $codes );

		$validated = $this->call( 'POST', '/admin/views/validate', array( 'document' => $changed ) )->get_data();
		$this->assertTrue( $validated['valid'] );
		$this->assertFalse( $this->call( 'POST', '/admin/views/validate', array( 'document' => array( 'schema_version' => 9 ) ) )->get_data()['valid'] );

		$this->assertSame( 404, $this->call( 'GET', '/admin/views/999999' )->get_status() );
		$this->assertSame( 200, $this->call( 'DELETE', '/admin/views/' . $view_id )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/admin/views/' . $view_id )->get_status(), 'A trashed view is gone from the editor.' );
		$this->assertSame( 404, $this->call( 'DELETE', '/admin/views/' . $view_id )->get_status() );
		$this->assertSame( array(), $this->call( 'GET', '/admin/views' )->get_data()['views'] );
	}

	public function test_publishing_a_view_checks_its_template_references(): void {
		$entry_template = $this->services->templates()->save( null, $this->template_document() );
		$broken         = $this->services->templates()->save( null, $this->template_document( '<p>{{entry.title}}</p>', TemplateRole::Entry, 'Soon broken' ) );

		$ok = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view( array( 'templates' => array( 'entry' => $entry_template ) ) ) ) );
		$this->assertSame( 201, $ok->get_status() );

		$missing = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view( array( 'templates' => array( 'entry' => 999999 ) ) ) ) );
		$this->assertSame( 422, $missing->get_status() );
		$this->assertSame( 'template_references_invalid', $missing->get_data()['code'] );
		$this->assertSame( 'template_missing', $missing->get_data()['templates'][0]['code'] );

		$wrong_role = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view( array( 'templates' => array( 'container' => $entry_template ) ) ) ) );
		$this->assertSame( 422, $wrong_role->get_status() );

		// A stored template that stopped compiling (for example after a formatter was removed) blocks publishing.
		$store = $this->services->store();
		$store->write( $broken, array( 'schema_version' => 1, 'title' => 'Broken', 'role' => 'entry', 'html' => '<p>{{entry.title|gone}}</p>', 'css' => '' ) );
		$bad = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view( array( 'templates' => array( 'entry' => $broken ) ) ) ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'template_invalid', $bad->get_data()['templates'][0]['code'] );
		$this->assertSame( 'unknown_formatter', $bad->get_data()['templates'][0]['errors'][0]['code'] );

		$draft = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view( array( 'templates' => array( 'entry' => $broken ) ) ), 'status' => 'draft' ) );
		$this->assertSame( 201, $draft->get_status(), 'A draft may reference an unfinished template.' );

		$no_source = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view( array( 'source' => 'post:gone' ) ) ) );
		$this->assertSame( 'source_missing', $no_source->get_data()['code'] );
		$bad_status = $this->call( 'POST', '/admin/views', array( 'document' => $this->raw_view(), 'status' => 'private' ) );
		$this->assertSame( 'status_invalid', $bad_status->get_data()['code'] );
	}

	public function test_view_preview_renders_unsaved_documents_and_templates(): void {
		$this->create_posts( 4 );
		$document = $this->raw_view( array( 'query' => array( 'per_page' => 3 ), 'layout' => array( 'type' => 'list' ) ) );

		$preview = $this->call( 'POST', '/admin/views/preview', array( 'document' => $document ) );
		$data    = $preview->get_data();
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( 3, $data['count'] );
		$this->assertSame( 4, $data['total'] );
		$this->assertStringContainsString( '<ul class="swp-list">', $data['html'] );
		$this->assertStringNotContainsString( '<style', $data['html'] );

		$second = $this->call( 'POST', '/admin/views/preview', array( 'document' => $document, 'page' => 2 ) )->get_data();
		$this->assertSame( 1, $second['count'] );

		$custom = $this->call(
			'POST',
			'/admin/views/preview',
			array(
				'document'           => $document,
				'entry_template'     => array( 'html' => '<p class="unsaved">{{entry.title}}</p>', 'css' => '.unsaved{color:red}' ),
				'container_template' => array( 'html' => '<section>{{view.entries}}</section>', 'css' => '' ),
			)
		)->get_data();
		$this->assertStringContainsString( '<p class="unsaved">Entry 1</p>', $custom['html'] );
		$this->assertStringContainsString( '<section>', $custom['html'] );
		$this->assertStringContainsString( '.unsaved{color:red}', $custom['css'] );

		$invalid = $this->call( 'POST', '/admin/views/preview', array( 'document' => array( 'schema_version' => 1 ) ) );
		$this->assertSame( 422, $invalid->get_status() );
		$broken = $this->call( 'POST', '/admin/views/preview', array( 'document' => $document, 'entry_template' => array( 'html' => '{{entry.nope}}' ) ) )->get_data();
		$this->assertSame( 'template_invalid', $broken['error'] );
		$oversized = $this->call( 'POST', '/admin/views/preview', array( 'document' => $document, 'entry_template' => array( 'html' => str_repeat( 'a', 300000 ) ) ) )->get_data();
		$this->assertStringContainsString( 'swp-row', $oversized['html'], 'An oversized unsaved template is ignored; the built-in one renders.' );
	}

	public function test_template_publication_validates_and_keeps_the_last_valid_version(): void {
		$created = $this->call( 'POST', '/admin/templates', array( 'document' => $this->template_document( '<p>{{entry.title}}</p>', TemplateRole::Entry, 'Card v1' )->to_array(), 'source' => 'post:post' ) );
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];

		$view_id = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $id ) ) ) );
		$this->assertSame( 1, count( $this->call( 'GET', '/admin/templates' )->get_data()['templates'] ) );
		$this->assertSame( 1, $this->call( 'GET', '/admin/templates' )->get_data()['templates'][0]['used_by'] );
		$this->assertSame( array( $view_id ), $this->call( 'GET', '/admin/templates/' . $id )->get_data()['used_by'] );

		$broken = $this->template_document( '<p>{{entry.unknown}}</p>', TemplateRole::Entry, 'Card v2 broken' )->to_array();
		$failed = $this->call( 'PUT', '/admin/templates/' . $id, array( 'document' => $broken, 'source' => 'post:post' ) );
		$this->assertSame( 422, $failed->get_status() );
		$this->assertSame( 'template_invalid', $failed->get_data()['code'] );
		$this->assertSame( 'unknown_placeholder', $failed->get_data()['sources']['post:post'][0]['code'] );
		$this->assertSame( '<p>{{entry.title}}</p>', $this->services->templates()->find( $id )->html, 'The last valid public version stays.' );

		$fixed   = $this->template_document( '<p class="v2">{{entry.title}}</p>', TemplateRole::Entry, 'Card v2' )->to_array();
		$updated = $this->call( 'PUT', '/admin/templates/' . $id, array( 'document' => $fixed, 'source' => 'post:post' ) );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( '<p class="v2">{{entry.title}}</p>', $this->services->templates()->find( $id )->html );

		$no_source = $this->call( 'POST', '/admin/templates', array( 'document' => $fixed ) );
		$this->assertSame( 'source_required', $no_source->get_data()['code'] );
		$unknown_source = $this->call( 'POST', '/admin/templates', array( 'document' => $fixed, 'source' => 'post:gone' ) );
		$this->assertSame( 'source_required', $unknown_source->get_data()['code'] );
		$invalid_document = $this->call( 'POST', '/admin/templates', array( 'document' => array( 'schema_version' => 1, 'title' => 'x', 'role' => 'nope', 'html' => 3 ), 'source' => 'post:post' ) );
		$this->assertSame( 422, $invalid_document->get_status() );
		$this->assertContains( 'role_invalid', array_column( $invalid_document->get_data()['errors'], 'code' ) );
	}

	public function test_template_is_checked_against_the_sources_of_the_views_using_it(): void {
		register_post_type( 'event', array( 'public' => true, 'label' => 'Events', 'taxonomies' => array( 'category' ) ) );
		\SW\Postlist\Services::reset();
		global $wp_rest_server;
		$wp_rest_server = null;
		$this->services = \SW\Postlist\Services::instance();
		$post_only      = $this->template_document( '<p>{{entry.terms.post_tag|join}}|{{entry.title|upper}}</p>' );
		$id             = $this->services->templates()->save( null, $post_only );
		$this->services->views()->save( null, $this->view_document( array( 'source' => 'post:event', 'templates' => array( 'entry' => $id ) ) ), 'draft' );

		$result = $this->call( 'PUT', '/admin/templates/' . $id, array( 'document' => $post_only->to_array(), 'source' => 'post:post' ) );

		$this->assertSame( 422, $result->get_status(), 'post_tag exists for posts but not for the event source of the view that uses the template.' );
		$this->assertArrayHasKey( 'post:event', $result->get_data()['sources'] );
		$this->assertArrayNotHasKey( 'post:post', $result->get_data()['sources'] );
	}

	public function test_template_validation_reports_positions_and_preview_uses_real_entries(): void {
		$this->create_posts( 3 );
		$document = $this->template_document( "<p>ok</p>\n  <p>{{entry.nope}}</p>" )->to_array();

		$validated = $this->call( 'POST', '/admin/templates/validate', array( 'document' => $document, 'source' => 'post:post' ) )->get_data();
		$this->assertFalse( $validated['valid'] );
		$this->assertSame( array( 'unknown_placeholder', 2, 6 ), array( $validated['errors'][0]['code'], $validated['errors'][0]['line'], $validated['errors'][0]['column'] ) );

		$good    = $this->template_document( '<p class="x">{{entry.title|upper}}</p>', TemplateRole::Entry )->to_array();
		$preview = $this->call( 'POST', '/admin/templates/preview', array( 'document' => $good, 'source' => 'post:post' ) )->get_data();
		$this->assertSame( 3, $preview['count'] );
		$this->assertStringContainsString( '<p class="x">ENTRY 1</p>', $preview['html'] );
		$this->assertNull( $preview['error'] );

		$container = $this->template_document( '<div class="wrap">{{view.title}}{{view.entries}}</div>', TemplateRole::Container )->to_array();
		$wrapped   = $this->call( 'POST', '/admin/templates/preview', array( 'document' => $container, 'source' => 'post:post' ) )->get_data();
		$this->assertStringContainsString( '<div class="wrap">Preview', $wrapped['html'] );
		$this->assertSame( 3, $wrapped['count'] );

		$untitled = $good;
		$untitled['title'] = '';
		$this->assertSame( 200, $this->call( 'POST', '/admin/templates/preview', array( 'document' => $untitled, 'source' => 'post:post' ) )->get_status(), 'The preview does not need a title.' );
		$this->assertTrue( $this->call( 'POST', '/admin/templates/validate', array( 'document' => $untitled, 'source' => 'post:post' ) )->get_data()['valid'] );
		$this->assertSame( 'invalid_document', $this->call( 'POST', '/admin/templates', array( 'document' => $untitled, 'source' => 'post:post' ) )->get_data()['code'], 'Publishing still requires a title.' );
		$this->assertSame( 422, $this->call( 'POST', '/admin/templates/preview', array( 'document' => $good, 'source' => 'nope' ) )->get_status() );
		$this->assertSame( 422, $this->call( 'POST', '/admin/templates/validate', array( 'document' => array() ) )->get_status() );
	}

	public function test_a_used_template_cannot_be_trashed_and_an_unused_one_can(): void {
		$id      = $this->services->templates()->save( null, $this->template_document() );
		$view_id = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $id ) ) ) );

		$blocked = $this->call( 'DELETE', '/admin/templates/' . $id );
		$this->assertSame( 409, $blocked->get_status() );
		$this->assertSame( 'template_in_use', $blocked->get_data()['code'] );
		$this->assertSame( array( $view_id ), $blocked->get_data()['used_by'] );
		$this->assertNotNull( $this->services->templates()->find( $id ) );

		$this->services->views()->save( $view_id, $this->view_document() );
		$this->assertSame( 200, $this->call( 'DELETE', '/admin/templates/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/admin/templates/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/admin/templates/' . $id )->get_status() );
	}

	public function test_acceptance_shared_template_is_published_once_for_all_views(): void {
		$this->create_posts( 3 );
		$shared = $this->services->templates()->save( null, $this->template_document( '<p class="v1">{{entry.title}}</p>', TemplateRole::Entry, 'Shared' ) );
		$own    = $this->services->templates()->save( null, $this->template_document( '<p class="own">{{entry.title}}</p>', TemplateRole::Entry, 'Own' ) );
		$view_a = $this->services->views()->save( null, $this->view_document( array( 'title' => 'A', 'templates' => array( 'entry' => $shared ) ) ) );
		$view_b = $this->services->views()->save( null, $this->view_document( array( 'title' => 'B', 'templates' => array( 'entry' => $shared ) ) ) );
		$view_c = $this->services->views()->save( null, $this->view_document( array( 'title' => 'C', 'templates' => array( 'entry' => $own ) ) ) );

		$render_all = function () use ( $view_a, $view_b, $view_c ): array {
			$out = array();
			foreach ( array( 'A' => $view_a, 'B' => $view_b, 'C' => $view_c ) as $name => $id ) {
				InstanceRegistry::reset();
				ViewRenderer::reset();
				$out[ $name ] = array(
					'shortcode' => do_shortcode( '[sw_postlist id="' . $id . '"]' ),
					'block'     => do_blocks( '<!-- wp:sw-postlist/view {"viewId":' . $id . '} /-->' ),
					'rest'      => $this->call( 'GET', '/views/' . $id . '/preview' )->get_data()['html'],
				);
			}
			return $out;
		};

		$before = $render_all();
		$render_all(); // The cache is filled and hit.
		foreach ( array( 'A', 'B' ) as $name ) {
			foreach ( $before[ $name ] as $channel => $html ) {
				$this->assertStringContainsString( 'class="v1"', $html, $name . ' ' . $channel );
			}
		}

		$updated = $this->call( 'PUT', '/admin/templates/' . $shared, array( 'document' => $this->template_document( '<p class="v2">{{entry.title}}!</p>', TemplateRole::Entry, 'Shared' )->to_array(), 'source' => 'post:post' ) );
		$this->assertSame( 200, $updated->get_status() );

		$after = $render_all();
		foreach ( array( 'A', 'B' ) as $name ) {
			foreach ( $after[ $name ] as $channel => $html ) {
				$this->assertStringContainsString( 'class="v2"', $html, $name . ' ' . $channel . ' shows the new version without being edited.' );
				$this->assertStringNotContainsString( 'class="v1"', $html );
			}
		}
		foreach ( $after['C'] as $channel => $html ) {
			$this->assertStringContainsString( 'class="own"', $html, 'The view with its own template keeps its look (' . $channel . ').' );
		}
	}

	public function test_sources_placeholders_formatters_and_diagnostics_for_the_editor(): void {
		add_filter( 'sw_postlist_entry_sources', static fn ( array $sources ): array => array_merge( $sources, array( 'junk' ) ) );
		\SW\Postlist\Services::reset();
		global $wp_rest_server;
		$wp_rest_server = null;

		$sources = $this->call( 'GET', '/admin/sources' )->get_data()['sources'];
		$ids     = array_column( $sources, 'id' );
		$this->assertContains( 'post:post', $ids );
		$this->assertContains( 'post:page', $ids );
		$this->assertSame( 'exact', $sources[ array_search( 'post:post', $ids, true ) ]['fields']['count_mode'] );

		$data = $this->call( 'GET', '/admin/placeholders', null, array( 'source' => 'post:post', 'role' => 'entry' ) )->get_data();
		$names = array_column( $data['placeholders'], 'name' );
		$this->assertContains( 'entry.title', $names );
		$this->assertContains( 'entry.terms.category', $names );
		$this->assertNotContains( 'view.entries', $names );
		$formatters = (array) $data['formatters'];
		$this->assertSame( array( 'text' ), $formatters['truncate']['inputs'] );
		$this->assertNotSame( '', $formatters['truncate']['help'] );
		$this->assertArrayHasKey( 'image', $formatters );

		$container = $this->call( 'GET', '/admin/placeholders', null, array( 'source' => 'post:post', 'role' => 'container' ) )->get_data();
		$this->assertContains( 'view.entries', array_column( $container['placeholders'], 'name' ) );
		$this->assertSame( 'entry', $this->call( 'GET', '/admin/placeholders', null, array( 'source' => 'post:post', 'role' => 'bogus' ) )->get_data()['role'] );
		$this->assertSame( 422, $this->call( 'GET', '/admin/placeholders', null, array( 'source' => 'nope' ) )->get_status() );

		$diagnostics = $this->call( 'GET', '/admin/diagnostics' )->get_data();
		$this->assertContains( 'source_interface', array_column( $diagnostics['registrations'], 'code' ) );
		$this->assertIsArray( $diagnostics['log'] );
	}

	public function test_settings_are_sanitized_and_invalidate_compiled_templates(): void {
		$saved = $this->call( 'PUT', '/admin/settings', array( 'meta_keys' => array( 'post:post' => array( 'subtitle', '_hidden', 'bad key', 'subtitle' ), 'post:nope' => array( 'x' ), 'post:page' => 'not-a-list' ) ) );

		$this->assertSame( array( 'post:post' => array( 'subtitle' ), 'post:page' => array() ), $saved->get_data()['meta_keys'] );
		$this->assertSame( array( 'post:post' => array( 'subtitle' ), 'post:page' => array() ), $this->call( 'GET', '/admin/settings' )->get_data()['meta_keys'] );
		$this->assertContains( 'subtitle', ( new \SW\Postlist\Infrastructure\WordPress\Engine\MetaAllowlist() )->keys( 'post:post' ) );

		$generation = $this->services->generations()->key( 'post:post', 1 );
		$this->call( 'PUT', '/admin/settings', array( 'meta_keys' => array() ) );
		$this->assertSame( array(), $this->call( 'GET', '/admin/settings' )->get_data()['meta_keys'] );
		$this->assertNotSame( $generation, $this->services->generations()->key( 'post:post', 1 ), 'Changing the allowlist invalidates every compiled template.' );
	}
}
