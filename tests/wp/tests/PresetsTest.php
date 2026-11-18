<?php
/**
 * M5 backend: bundled, extension and user presets; applying a preset creates ordinary templates.
 *
 * @package sw-postlist
 */

class PresetsTest extends SWP_TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', array( \SW\Postlist\Plugin::instance(), 'register_rest' ) );
		$this->admin();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed>|null $body JSON body.
	 */
	private function call( string $method, string $route, ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-postlist/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function preset( string $id = 'my-preset', array $overrides = array() ): array {
		return array_replace(
			array(
				'schema_version' => 1,
				'id'             => $id,
				'title'          => 'My preset',
				'description'    => 'Test',
				'layout'         => array( 'type' => 'grid', 'columns' => array( 'desktop' => 2 ) ),
				'container'      => array( 'html' => '<div>{{view.entries}}</div>', 'css' => '' ),
				'entry'          => array( 'html' => '<p>{{entry.title}}</p>', 'css' => '.x{color:red}' ),
				'builder'        => null,
			),
			$overrides
		);
	}

	private function ids( WP_REST_Response $response ): array {
		return array_column( $response->get_data()['presets'], 'id' );
	}

	public function test_bundled_presets_are_valid_and_compile_for_posts(): void {
		$list = $this->call( 'GET', '/admin/presets' )->get_data();

		$this->assertSame( array( 'builder-card', 'card-grid', 'compact-list', 'dark-overlay', 'date-badge-list', 'editorial-hero', 'magazine-cards', 'neo-brutalist', 'news-teaser', 'teaser-pills' ), array_column( $list['presets'], 'id' ) );
		$this->assertSame( array(), $list['diagnostics'] );
		$source = $this->services->sources()->get( 'post:post' );
		foreach ( $list['presets'] as $preset ) {
			$this->assertSame( 'bundled', $preset['origin'] );
			$this->assertFalse( $preset['can_reset'] );
			foreach ( array( 'container' => \SW\Postlist\Domain\Template\TemplateRole::Container, 'entry' => \SW\Postlist\Domain\Template\TemplateRole::Entry ) as $part => $role ) {
				$document = new \SW\Postlist\Domain\Template\TemplateDocument( 'x', $role, $preset[ $part ]['html'], $preset[ $part ]['css'] );
				$result   = $this->services->template_compilation()->validate( $document, $source );
				$this->assertTrue( $result->is_valid(), $preset['id'] . ' ' . $part . ': ' . wp_json_encode( array_map( static fn ( $error ) => $error->to_array(), $result->errors ) ) );
			}
		}
	}

	public function test_every_route_requires_the_management_capability(): void {
		$routes = array(
			array( 'GET', '/admin/presets' ),
			array( 'POST', '/admin/presets' ),
			array( 'DELETE', '/admin/presets/card-grid' ),
			array( 'POST', '/admin/presets/card-grid/duplicate' ),
			array( 'POST', '/admin/presets/card-grid/apply' ),
		);
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'editor' ) ) ) as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( $routes as list( $method, $route ) ) {
				$this->assertContains( $this->call( $method, $route, array() )->get_status(), array( 401, 403 ), $method . ' ' . $route );
			}
		}
	}

	public function test_user_presets_can_override_bundled_ones_and_be_reset(): void {
		$saved = $this->call( 'POST', '/admin/presets', array( 'preset' => $this->preset( 'my-preset' ) ) );
		$this->assertSame( 201, $saved->get_status() );
		$this->assertContains( 'my-preset', $this->ids( $this->call( 'GET', '/admin/presets' ) ) );

		$override = $this->preset( 'card-grid', array( 'title' => 'My cards' ) );
		$this->call( 'POST', '/admin/presets', array( 'preset' => $override ) );
		$entry = array_values( array_filter( $this->call( 'GET', '/admin/presets' )->get_data()['presets'], static fn ( array $preset ): bool => 'card-grid' === $preset['id'] ) )[0];
		$this->assertSame( array( 'My cards', 'user', 'bundled', true ), array( $entry['title'], $entry['origin'], $entry['overrides'], $entry['can_reset'] ) );

		$this->assertSame( 200, $this->call( 'DELETE', '/admin/presets/card-grid' )->get_status(), 'Resetting removes the override.' );
		$entry = array_values( array_filter( $this->call( 'GET', '/admin/presets' )->get_data()['presets'], static fn ( array $preset ): bool => 'card-grid' === $preset['id'] ) )[0];
		$this->assertSame( array( 'Card grid', 'bundled' ), array( $entry['title'], $entry['origin'] ) );

		$this->assertSame( 409, $this->call( 'DELETE', '/admin/presets/card-grid' )->get_status(), 'A bundled preset cannot be deleted.' );
		$this->assertSame( 404, $this->call( 'DELETE', '/admin/presets/does-not-exist' )->get_status() );
		$this->assertSame( 200, $this->call( 'DELETE', '/admin/presets/my-preset' )->get_status() );
		$this->assertNotContains( 'my-preset', $this->ids( $this->call( 'GET', '/admin/presets' ) ) );
	}

	public function test_invalid_presets_are_rejected_with_findings(): void {
		$cases = array(
			array( array( 'schema_version' => 2 ), 'schema_version_unsupported' ),
			array( $this->preset( 'Bad ID!' ), 'preset_id_invalid' ),
			array( $this->preset( 'x', array( 'title' => '' ) ), 'title_invalid' ),
			array( $this->preset( 'x', array( 'layout' => array( 'type' => 'grid', 'columns' => array( 'desktop' => 99 ) ) ) ), 'layout_option_invalid' ),
			array( $this->preset( 'x', array( 'container' => array( 'html' => 5, 'css' => '' ) ) ), 'html_invalid' ),
			array( $this->preset( 'x', array( 'builder' => 'text' ) ), 'builder_invalid' ),
		);
		foreach ( $cases as list( $document, $code ) ) {
			$response = $this->call( 'POST', '/admin/presets', array( 'preset' => $document ) );
			$this->assertSame( 422, $response->get_status(), $code );
			$this->assertContains( $code, array_column( $response->get_data()['errors'], 'code' ) );
		}
		$this->assertSame( array(), array_values( array_filter( $this->ids( $this->call( 'GET', '/admin/presets' ) ), static fn ( string $id ): bool => 'x' === $id ) ) );
	}

	public function test_extensions_register_presets_and_faults_are_diagnosed(): void {
		add_filter(
			'sw_postlist_presets',
			fn ( array $presets ): array => $presets + array(
				'from-plugin' => $this->preset( 'from-plugin', array( 'title' => 'Plugin preset' ) ),
				'broken'      => array( 'schema_version' => 1, 'id' => 'broken' ),
				'junk'        => 'not a preset',
			)
		);
		$list = $this->call( 'GET', '/admin/presets' )->get_data();

		$entry = array_values( array_filter( $list['presets'], static fn ( array $preset ): bool => 'from-plugin' === $preset['id'] ) )[0];
		$this->assertSame( 'extension', $entry['origin'] );
		$this->assertSame( array( 'preset_registration_invalid', 'preset_registration_invalid' ), array_column( $list['diagnostics'], 'code' ) );
		$this->assertContains( 'preset_registration_invalid', array_column( $this->services->diagnostics(), 'code' ) );

		remove_all_filters( 'sw_postlist_presets' );
		add_filter( 'sw_postlist_presets', static fn () => 'broken' );
		$this->assertSame( 'presets_not_array', $this->call( 'GET', '/admin/presets' )->get_data()['diagnostics'][0]['code'] );
	}

	public function test_invalid_user_presets_and_bundled_files_are_diagnosed(): void {
		update_option( \SW\Postlist\Infrastructure\WordPress\PresetRepository::OPTION, array( 'broken' => array( 'schema_version' => 1 ), 7 => array(), 'text' => 'x' ) );
		$list = $this->call( 'GET', '/admin/presets' )->get_data();

		$this->assertContains( 'user_preset_invalid', array_column( $list['diagnostics'], 'code' ) );
		$this->assertSame( 10, count( $list['presets'] ), 'Only the bundled presets remain.' );

		$directory = sys_get_temp_dir() . '/swp-presets-' . getmypid();
		wp_mkdir_p( $directory );
		file_put_contents( $directory . '/broken.json', '{ not json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$repository = new \SW\Postlist\Infrastructure\WordPress\PresetRepository( $this->services->preset_validator(), $directory );
		$this->assertSame( array(), $repository->all() );
		$this->assertSame( 'preset_file_invalid', $repository->diagnostics()[0]['code'] );
		unlink( $directory . '/broken.json' );
		rmdir( $directory );
	}

	public function test_duplicate_creates_a_user_copy_with_a_new_id(): void {
		$copy = $this->call( 'POST', '/admin/presets/card-grid/duplicate', array( 'id' => 'card-grid-2', 'title' => 'Cards 2' ) );
		$this->assertSame( 201, $copy->get_status() );
		$entry = array_values( array_filter( $this->call( 'GET', '/admin/presets' )->get_data()['presets'], static fn ( array $preset ): bool => 'card-grid-2' === $preset['id'] ) )[0];
		$this->assertSame( array( 'Cards 2', 'user' ), array( $entry['title'], $entry['origin'] ) );
		$original = array_values( array_filter( $this->call( 'GET', '/admin/presets' )->get_data()['presets'], static fn ( array $preset ): bool => 'card-grid' === $preset['id'] ) )[0];
		$this->assertSame( $original['entry'], $entry['entry'], 'The copy has the same content.' );

		$this->assertSame( 'preset_id_taken', $this->call( 'POST', '/admin/presets/card-grid/duplicate', array( 'id' => 'card-grid-2' ) )->get_data()['code'] );
		$this->assertSame( 'preset_id_taken', $this->call( 'POST', '/admin/presets/card-grid/duplicate', array() )->get_data()['code'] );
		$this->assertSame( 422, $this->call( 'POST', '/admin/presets/card-grid/duplicate', array( 'id' => 'Bad ID' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/admin/presets/nope/duplicate', array( 'id' => 'x' ) )->get_status() );
	}

	public function test_apply_creates_ordinary_templates_and_nothing_on_failure(): void {
		$applied = $this->call( 'POST', '/admin/presets/card-grid/apply', array( 'source' => 'post:post' ) );

		$this->assertSame( 201, $applied->get_status() );
		$data      = $applied->get_data();
		$container = $this->services->templates()->find( $data['container_template'] );
		$entry     = $this->services->templates()->find( $data['entry_template'] );
		$this->assertSame( array( 'container', 'entry' ), array( $container->role->value, $entry->role->value ) );
		$this->assertStringContainsString( 'card__title', $entry->html );
		$this->assertSame( 'grid', $data['layout']['type'] );

		$this->call( 'POST', '/admin/presets', array( 'preset' => $this->preset( 'bad-for-pages', array( 'entry' => array( 'html' => '<p>{{entry.terms.post_tag|join}}</p>', 'css' => '' ) ) ) ) );
		$before = count( $this->services->templates()->summaries() );
		$failed = $this->call( 'POST', '/admin/presets/bad-for-pages/apply', array( 'source' => 'post:page' ) );
		$this->assertSame( 422, $failed->get_status() );
		$this->assertSame( 'preset_template_invalid', $failed->get_data()['code'] );
		$this->assertSame( 'entry', $failed->get_data()['role'] );
		$this->assertSame( $before, count( $this->services->templates()->summaries() ), 'Nothing is written when a template does not compile.' );

		$this->assertSame( 'source_required', $this->call( 'POST', '/admin/presets/card-grid/apply', array() )->get_data()['code'] );
		$this->assertSame( 404, $this->call( 'POST', '/admin/presets/nope/apply', array( 'source' => 'post:post' ) )->get_status() );
	}

	public function test_every_bundled_preset_renders_valid_markup_with_and_without_images(): void {
		$this->admin();
		$term = self::factory()->category->create( array( 'name' => 'Team' ) );
		$with = self::factory()->post->create( array( 'post_title' => 'With image', 'post_category' => array( $term ), 'post_excerpt' => 'A short excerpt for the card.' ) );
		self::factory()->post->create( array( 'post_title' => 'Without image', 'post_category' => array( $term ), 'post_excerpt' => 'Another excerpt.' ) );
		$attachment = self::factory()->attachment->create_upload_object( dirname( __DIR__, 2 ) . '/Fixtures/pixel.png', $with );
		set_post_thumbnail( $with, $attachment );

		foreach ( $this->call( 'GET', '/admin/presets' )->get_data()['presets'] as $preset ) {
			$applied = $this->call( 'POST', '/admin/presets/' . $preset['id'] . '/apply', array( 'source' => 'post:post' ) );
			$this->assertSame( 201, $applied->get_status(), $preset['id'] );
			$data   = $applied->get_data();
			$view   = $this->services->views()->save(
				null,
				$this->view_document(
					array(
						'title'     => $preset['id'],
						'query'     => array( 'orderby' => 'date', 'order' => 'desc' ),
						'layout'    => $data['layout'],
						'templates' => array( 'container' => $data['container_template'], 'entry' => $data['entry_template'] ),
						'filters'   => array( array( 'type' => 'taxonomy', 'taxonomy' => 'category' ) ),
					)
				)
			);
			\SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry::reset();
			\SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer::reset();
			( new \SW\Postlist\Presentation\Shortcode\PostlistShortcode( $this->services->renderer() ) )->register();
			$html = do_shortcode( '[sw_postlist id="' . $view . '"]' );

			$this->assertSame( 2, substr_count( $html, 'class="swp-item' ), $preset['id'] );
			$this->assertStringContainsString( 'Without image', $html, $preset['id'] );
			if ( str_contains( $preset['entry']['html'], 'entry.image' ) ) {
				$this->assertStringContainsString( '<img', $html, $preset['id'] . ' shows the featured image' );
			}
			$this->assertStringNotContainsString( '{{', $html, $preset['id'] );
			// A list item is only valid inside ul/ol.
			$this->assertSame( preg_match( '/<li[\s>]/', $html ) ? 1 : 0, preg_match( '/<(ul|ol)[\s>]/', $html ), $preset['id'] . ' li needs a list' );
		}
	}
}
