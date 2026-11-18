<?php
/**
 * M6: Masonry, hover grid, slider, the layout registry and the on-demand loading of layout modules.
 *
 * @package sw-postlist
 */

use SW\Postlist\Application\Layout\LayoutDefinition;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;
use SW\Postlist\Services;

class LayoutsTest extends SWP_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->reset_rendering();
	}

	public function tear_down(): void {
		InstanceRegistry::reset();
		ViewRenderer::reset();
		wp_dequeue_script( 'sw-postlist-masonry' );
		wp_dequeue_script( 'sw-postlist-slider' );
		parent::tear_down();
	}

	private function reset_rendering(): void {
		InstanceRegistry::reset();
		ViewRenderer::reset();
		( new PostlistShortcode( $this->services->renderer() ) )->register();
	}

	/**
	 * @param array<string, mixed> $layout Layout of the view.
	 */
	private function render_layout( array $layout ): string {
		$view_id = $this->services->views()->save( null, $this->view_document( array( 'layout' => $layout ) ) );
		$this->reset_rendering();
		return do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
	}

	public function test_every_builtin_layout_renders_its_markup_and_entries(): void {
		$this->create_posts( 3 );
		$expected = array(
			'grid'       => 'swp-grid',
			'list'       => '<ul class="swp-list">',
			'masonry'    => 'swp-masonry',
			'hover_grid' => 'swp-hover-grid',
			'slider'     => 'swp-slider__track',
		);
		foreach ( $expected as $type => $marker ) {
			$html = $this->render_layout( array( 'type' => $type ) );
			$this->assertStringContainsString( $marker, $html, $type );
			$this->assertStringContainsString( 'swp-layout-' . $type, $html, $type );
			$this->assertSame( 3, substr_count( $html, 'class="swp-item' ), $type );
		}
	}

	public function test_hover_card_and_slider_markup_are_accessible(): void {
		$this->create_posts( 2 );
		$hover = $this->render_layout( array( 'type' => 'hover_grid' ) );
		$this->assertStringContainsString( 'swp-hover__title', $hover );
		$this->assertStringContainsString( 'swp-hover__button', $hover, 'Details are real links, reachable by keyboard.' );

		$slider = $this->render_layout( array( 'type' => 'slider' ) );
		$this->assertStringContainsString( 'role="group"', $slider );
		$this->assertStringContainsString( 'aria-roledescription="carousel"', $slider );
		$this->assertStringContainsString( 'tabindex="0"', $slider );
	}

	public function test_slider_options_reach_the_module_as_json_and_slides_as_css_variables(): void {
		$this->create_posts( 2 );
		$html = $this->render_layout(
			array(
				'type'     => 'slider',
				'slides'   => array( 'desktop' => 4, 'mobile' => 1 ),
				'autoplay' => true,
				'interval' => 4500,
				'loop'     => true,
				'dots'     => false,
			)
		);
		$this->assertStringContainsString( 'data-swp-module="slider"', $html );
		$this->assertMatchesRegularExpression( '/data-swp-options="([^"]*)"/', $html );
		preg_match( '/data-swp-options="([^"]*)"/', $html, $found );
		$options = json_decode( html_entity_decode( $found[1] ), true );
		$this->assertTrue( $options['autoplay'] );
		$this->assertSame( 4500, $options['interval'] );
		$this->assertTrue( $options['loop'] );
		$this->assertFalse( $options['dots'] );
		$this->assertStringContainsString( '--swp-slides-desktop:4;', $html );
		$this->assertStringContainsString( '--swp-slides-tablet:2;', $html );
	}

	public function test_only_the_needed_module_is_loaded(): void {
		$this->create_posts( 2 );
		$this->render_layout( array( 'type' => 'grid' ) );
		$this->render_layout( array( 'type' => 'list' ) );
		$this->render_layout( array( 'type' => 'hover_grid' ) );
		$this->assertFalse( wp_script_is( 'sw-postlist-masonry', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'sw-postlist-slider', 'enqueued' ) );

		$this->render_layout( array( 'type' => 'masonry' ) );
		$this->assertTrue( wp_script_is( 'sw-postlist-masonry', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'sw-postlist-slider', 'enqueued' ) );

		$this->render_layout( array( 'type' => 'slider' ) );
		$this->assertTrue( wp_script_is( 'sw-postlist-slider', 'enqueued' ) );
		$scripts = wp_scripts()->registered;
		$this->assertSame( SW_POSTLIST_VERSION, $scripts['sw-postlist-slider']->ver, 'Cache busting with the plugin version.' );
		$this->assertSame( 'defer', $scripts['sw-postlist-slider']->extra['strategy'] );
		$before = wp_scripts()->get_data( 'sw-postlist-slider', 'before' );
		$this->assertStringContainsString( 'SWPostlist.i18n', implode( '', (array) $before ), 'Control labels are translatable and set before the module runs.' );
		$this->assertStringContainsString( 'Previous slide', implode( '', (array) $before ) );
		$this->assertSame( 1, $scripts['sw-postlist-slider']->extra['group'], 'Loaded in the footer.' );
	}

	public function test_missing_or_invalid_module_names_load_nothing(): void {
		\SW\Postlist\Presentation\Frontend\FrontendAssets::enqueue_module( '../etc/passwd' );
		\SW\Postlist\Presentation\Frontend\FrontendAssets::enqueue_module( 'does-not-exist' );
		$this->assertFalse( wp_script_is( 'sw-postlist-does-not-exist', 'registered' ) );
	}

	public function test_template_defaults_exist_for_every_layout_and_role(): void {
		$builtin = $this->services->layouts()->ids();
		$this->assertSame( array( 'grid', 'list', 'masonry', 'hover_grid', 'slider' ), $builtin );
		foreach ( $builtin as $type ) {
			$this->assertNotSame( '', \SW\Postlist\Infrastructure\WordPress\Render\BuiltinTemplates::container( $type ), $type );
			$this->assertNotSame( '', \SW\Postlist\Infrastructure\WordPress\Render\BuiltinTemplates::entry( $type ), $type );
		}
	}

	public function test_third_party_layout_through_the_filter_validates_renders_and_wraps(): void {
		add_filter(
			'sw_postlist_layouts',
			static function ( array $layouts ): array {
				$layouts[] = new LayoutDefinition(
					'timeline',
					'Timeline',
					array(
						'dense' => array( 'type' => 'bool' ),
						'mark'  => array( 'type' => 'enum', 'values' => array( 'dot', 'line' ) ),
					),
					null,
					static fn ( string $items, array $options ): string => '<div class="acme-timeline" data-dense="' . ( ! empty( $options['dense'] ) ? '1' : '0' ) . '">' . $items . '</div>'
				);
				return $layouts;
			}
		);
		Services::reset();
		$this->services = Services::instance();
		$this->create_posts( 2 );

		$html = $this->render_layout( array( 'type' => 'timeline', 'dense' => true, 'mark' => 'line' ) );
		$this->assertStringContainsString( 'class="acme-timeline" data-dense="1"', $html );
		$this->assertSame( 2, substr_count( $html, 'class="swp-item' ) );

		$invalid = $this->services->view_validator()->validate(
			array(
				'schema_version' => 1,
				'title'          => 'Bad',
				'source'         => 'post:post',
				'layout'         => array( 'type' => 'timeline', 'mark' => 'star', 'unknown' => 1 ),
				'pagination'     => array( 'mode' => 'classic' ),
			)
		);
		$codes = array_map( static fn ( $error ) => $error->code, $invalid->errors() );
		$this->assertContains( 'layout_option_invalid', $codes );
		$this->assertContains( 'layout_unknown_key', $codes );
	}

	public function test_failing_or_wrong_third_party_callbacks_render_a_neutral_error(): void {
		add_filter(
			'sw_postlist_layouts',
			static function ( array $layouts ): array {
				$layouts[] = new LayoutDefinition( 'boom', 'Boom', array(), null, static function (): string {
					throw new \RuntimeException( 'secret detail' );
				} );
				$layouts[] = new LayoutDefinition( 'wrongtype', 'Wrong', array(), null, static fn (): int => 5 );
				return $layouts;
			}
		);
		Services::reset();
		$this->services = Services::instance();
		$this->create_posts( 1 );

		foreach ( array( 'boom', 'wrongtype' ) as $type ) {
			$html = $this->render_layout( array( 'type' => $type ) );
			$this->assertStringNotContainsString( 'secret detail', $html );
			$this->assertStringNotContainsString( 'swp-item', $html );
		}
	}

	public function test_faulty_registrations_are_diagnosed_and_do_not_replace_builtins(): void {
		add_filter(
			'sw_postlist_layouts',
			static fn ( array $layouts ): array => array_merge(
				$layouts,
				array(
					'junk',
					new LayoutDefinition( 'grid', 'Duplicate' ),
					new LayoutDefinition( 'Bad Id', 'Bad' ),
					new LayoutDefinition( 'nolabel', ' ' ),
					new LayoutDefinition( 'badschema', 'Schema', array( 'x' => array( 'type' => 'magic' ) ) ),
					new LayoutDefinition( 'badcb', 'Callback', array(), null, 'no_such_function' ),
					new LayoutDefinition( 'badmod', 'Module', array(), '../x' ),
					new LayoutDefinition( 'badtag', 'Tag', array(), null, null, 'span' ),
				)
			)
		);
		Services::reset();
		$this->services = Services::instance();

		$codes = array_column( $this->services->layouts()->diagnostics(), 'code' );
		foreach ( array( 'layout_interface', 'layout_id_duplicate', 'layout_id_invalid', 'layout_label_empty', 'layout_schema_invalid', 'layout_callback_invalid', 'layout_module_invalid', 'layout_item_tag_invalid' ) as $code ) {
			$this->assertContains( $code, $codes );
		}
		$this->assertSame( 'Grid', $this->services->layouts()->get( 'grid' )?->label );
		$this->assertSame( array( 'grid', 'list', 'masonry', 'hover_grid', 'slider' ), $this->services->layouts()->ids() );
		$this->assertNotEmpty( array_filter( $this->services->diagnostics(), static fn ( array $row ): bool => 'layout_id_duplicate' === $row['code'] ) );
	}

	public function test_non_array_filter_result_is_reported(): void {
		add_filter( 'sw_postlist_layouts', static fn (): string => 'oops' );
		Services::reset();
		$this->services = Services::instance();
		$this->assertSame( array( 'layouts_not_array' ), array_column( $this->services->layouts()->diagnostics(), 'code' ) );
		$this->assertCount( 5, $this->services->layouts()->all() );
	}

	public function test_layout_route_lists_registered_layouts_for_managers_only(): void {
		$this->admin();
		$request = new WP_REST_Request( 'GET', '/sw-postlist/v1/admin/layouts' );
		global $wp_rest_server;
		$wp_rest_server = null;
		$response       = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['layouts'];
		$this->assertSame( array( 'grid', 'list', 'masonry', 'hover_grid', 'slider' ), array_column( $data, 'id' ) );
		$this->assertSame( 'slider', $data[4]['module'] );
		$this->assertArrayHasKey( 'autoplay', (array) $data[4]['schema'] );

		wp_set_current_user( 0 );
		$this->assertContains( rest_get_server()->dispatch( $request )->get_status(), array( 401, 403 ) );
	}

	public function test_filter_type_route_lists_registered_types_for_managers_only(): void {
		add_filter(
			'sw_postlist_filter_types',
			static function ( array $types ): array {
				$types[] = new \SW\Postlist\Application\Filter\FilterTypeDefinition( 'region', 'Region', array(), static fn (): array => array(), static fn (): string => '' );
				return $types;
			}
		);
		Services::reset();
		$this->services = Services::instance();
		$this->admin();
		global $wp_rest_server;
		$wp_rest_server = null;
		$request        = new WP_REST_Request( 'GET', '/sw-postlist/v1/admin/filter-types' );
		$data           = rest_get_server()->dispatch( $request )->get_data()['filter_types'];
		$this->assertSame( array( 'taxonomy', 'author', 'search', 'sort', 'region' ), array_column( $data, 'id' ) );
		$this->assertTrue( $data[0]['builtin'] );
		$this->assertFalse( $data[4]['builtin'] );
		$this->assertArrayHasKey( 'taxonomy', (array) $data[0]['schema'] );

		wp_set_current_user( 0 );
		$this->assertContains( rest_get_server()->dispatch( $request )->get_status(), array( 401, 403 ) );
	}

	public function test_list_entries_are_only_li_inside_a_real_list(): void {
		$this->admin();
		$this->create_posts( 2 );
		$builtin = $this->render_layout( array( 'type' => 'list' ) );
		$this->assertStringContainsString( '<ul class="swp-list"><li class="swp-item', $builtin );

		$div_container = $this->services->templates()->save( null, $this->template_document( '<div class="mine">{{view.entries}}</div>', \SW\Postlist\Domain\Template\TemplateRole::Container, 'Div container' ) );
		$ol_container  = $this->services->templates()->save( null, $this->template_document( '<ol class="mine">{{view.entries}}</ol>', \SW\Postlist\Domain\Template\TemplateRole::Container, 'Ol container' ) );
		foreach ( array(
			$div_container => array( '<div class="swp-item', '<li class="swp-item' ),
			$ol_container  => array( '<li class="swp-item', '<div class="swp-item' ),
		) as $template => list( $expected, $absent ) ) {
			$view_id = $this->services->views()->save( null, $this->view_document( array( 'layout' => array( 'type' => 'list' ), 'templates' => array( 'container' => $template ) ) ) );
			$this->reset_rendering();
			$html = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
			$this->assertStringContainsString( $expected, $html );
			$this->assertStringNotContainsString( $absent, $html );
		}
	}
}
