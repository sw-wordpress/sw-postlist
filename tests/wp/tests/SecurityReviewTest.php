<?php
/**
 * M9 security review as regression tests: import, public route, template output and filter input paths.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;

class SecurityReviewTest extends SWP_TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
		InstanceRegistry::reset();
		ViewRenderer::reset();
		( new PostlistShortcode( $this->services->renderer() ) )->register();
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function rest( string $method, string $route, array $params = array(), ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-postlist/v1' . $route );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param array<string, mixed> $template Template document of the package.
	 * @return array<string, mixed>
	 */
	private function package_with( array $template ): array {
		return array(
			'format'         => 'sw-postlist-package',
			'format_version' => 1,
			'templates'      => array( 't1' => array_merge( array( 'schema_version' => 1, 'title' => 'T', 'role' => 'entry', 'html' => '<p>{{entry.title}}</p>', 'css' => '' ), $template ) ),
			'views'          => array(
				array(
					'key'      => 'v1',
					'status'   => 'publish',
					'document' => array(
						'schema_version' => 1,
						'title'          => 'Imported',
						'source'         => 'post:post',
						'layout'         => array( 'type' => 'grid' ),
						'pagination'     => array( 'mode' => 'classic' ),
						'templates'      => array( 'container' => null, 'entry' => 't1' ),
					),
				),
			),
		);
	}

	public function test_import_refuses_active_content_in_templates_and_creates_nothing(): void {
		$this->admin();
		$before = wp_count_posts( \SW\Postlist\Infrastructure\WordPress\PostTypes::TEMPLATE )->publish;
		foreach ( array(
			array( 'html' => '<script>alert(1)</script>' ),
			array( 'html' => '<img src=x onerror=alert(1)>' ),
			array( 'html' => '<a href="javascript:alert(1)">x</a>' ),
			array( 'html' => '<p style="background:url(https://evil.example/x)">{{entry.title}}</p>' ),
			array( 'html' => '<p style="width:expression(alert(1))">{{entry.title}}</p>' ),
			array( 'html' => '<iframe src="https://evil.example"></iframe>' ),
			array( 'html' => '{{{entry.content}}}' ),
			array( 'css' => '.a{background:url(https://evil.example/x)}' ),
			array( 'css' => '@import "https://evil.example/x.css";' ),
			array( 'css' => '.a{width:expression(alert(1))}' ),
			array( 'css' => '</style><script>alert(1)</script>' ),
		) as $template ) {
			$response = $this->rest( 'POST', '/admin/import', array(), array( 'package' => $this->package_with( $template ) ) );
			$this->assertSame( 422, $response->get_status(), (string) wp_json_encode( $template ) . " " . wp_json_encode( $response->get_data() ) );
		}
		$this->assertSame( $before, wp_count_posts( \SW\Postlist\Infrastructure\WordPress\PostTypes::TEMPLATE )->publish, 'Nothing was created by refused imports.' );
	}

	public function test_import_requires_the_management_capability_and_is_not_open_to_guests(): void {
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'editor' ) ) ) as $user ) {
			wp_set_current_user( $user );
			$this->assertContains( $this->rest( 'POST', '/admin/import', array(), array( 'package' => $this->package_with( array() ) ) )->get_status(), array( 401, 403 ) );
			$this->assertContains( $this->rest( 'POST', '/admin/export', array(), array( 'views' => array( 1 ) ) )->get_status(), array( 401, 403 ) );
		}
	}

	public function test_markup_in_titles_is_escaped_in_the_output(): void {
		$this->admin();
		$container = $this->services->templates()->save( null, $this->template_document( '<h2>{{view.title}}</h2>{{view.entries}}', TemplateRole::Container, 'Frame' ) );
		$view      = $this->services->views()->save( null, $this->view_document( array( 'title' => '<img src=x onerror=alert(1)>', 'templates' => array( 'container' => $container ) ) ) );
		self::factory()->post->create( array( 'post_title' => '<script>alert(2)</script>Hello' ) );
		wp_set_current_user( 0 );
		$html = do_shortcode( '[sw_postlist id="' . $view . '"]' );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}

	public function test_the_public_route_only_renders_published_views(): void {
		$this->admin();
		$template = $this->services->templates()->save( null, $this->template_document() );
		$post     = self::factory()->post->create();
		$page     = self::factory()->post->create( array( 'post_type' => 'page' ) );
		wp_set_current_user( 0 );
		foreach ( array( $template, $post, $page, 0 ) as $id ) {
			$response = $this->rest( 'GET', '/views/' . $id . '/render', array( 'key' => 'k' ) );
			$this->assertSame( 404, $response->get_status(), 'ID ' . $id );
			$this->assertStringNotContainsString( 'Sample', (string) wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_filter_input_with_sql_and_markup_payloads_changes_nothing(): void {
		$this->admin();
		$view = $this->services->views()->save( null, $this->view_document( array( 'filters' => array( array( 'type' => 'search' ), array( 'type' => 'sort', 'options' => array( 'title' ) ), array( 'type' => 'author' ), array( 'type' => 'taxonomy', 'taxonomy' => 'category' ) ) ) ) );
		$this->create_posts( 3 );
		wp_set_current_user( 0 );
		$key = 'view-' . $view . '-1';
		foreach ( array(
			'swp[' . $key . '][f][q]=%27%20OR%201%3D1%20--',
			'swp[' . $key . '][f][sort]=title%3B%20DROP%20TABLE%20wp_posts',
			'swp[' . $key . '][f][sort]=post_password',
			'swp[' . $key . '][f][author]=1%20OR%201%3D1',
			'swp[' . $key . '][f][tax_category]=1)%20OR%20(1%3D1',
			'swp[' . $key . '][f][q]=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
		) as $query ) {
			$response = $this->rest( 'GET', '/views/' . $view . '/render', array( 'key' => $key, 'query' => $query ) );
			$this->assertSame( 200, $response->get_status(), $query );
			$this->assertStringNotContainsString( '<script>', $response->get_data()['html'], $query );
		}
		global $wpdb;
		$this->assertNotNull( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->posts}'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test of table existence.
		$all = $this->rest( 'GET', '/views/' . $view . '/render', array( 'key' => $key, 'query' => 'swp[' . $key . '][f][author]=1%20OR%201%3D1' ) )->get_data();
		$this->assertSame( 3, $all['total'], 'A malformed author value is ignored, not interpreted.' );
	}

	public function test_layout_options_cannot_inject_css_or_markup(): void {
		$this->admin();
		foreach ( array( 'red;}</style><script>', 'a b', 'url(x)', '' ) as $accent ) {
			$result = $this->services->view_validator()->validate(
				array(
					'schema_version' => 1,
					'title'          => 'x',
					'source'         => 'post:post',
					'layout'         => array( 'type' => 'grid', 'accent' => $accent ),
					'pagination'     => array( 'mode' => 'classic' ),
				)
			);
			$this->assertFalse( $result->is_valid(), $accent );
		}
	}
}
