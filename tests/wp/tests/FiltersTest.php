<?php
/**
 * M7 (server side): visitor filters narrow the selection, keep totals and pages consistent, never reveal hidden
 * content and work as a plain GET form without JavaScript.
 *
 * @package sw-postlist
 */

use SW\Postlist\Application\Filter\FilterTypeDefinition;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;
use SW\Postlist\Services;

class FiltersTest extends SWP_TestCase {

	private int $cat_a;
	private int $cat_b;
	private int $author_one;
	private int $author_two;

	public function set_up(): void {
		parent::set_up();
		$this->reset_rendering();
		$_GET = array();
		$this->cat_a      = self::factory()->category->create( array( 'name' => 'Alpha cat' ) );
		$this->cat_b      = self::factory()->category->create( array( 'name' => 'Beta cat' ) );
		$this->author_one = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Ann Author' ) );
		$this->author_two = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Bob Author' ) );
		$posts            = array(
			array( 'Apple pie', $this->cat_a, $this->author_one, 'publish' ),
			array( 'Banana split', $this->cat_a, $this->author_two, 'publish' ),
			array( 'Cherry tart', $this->cat_a, $this->author_one, 'publish' ),
			array( 'Date cake', $this->cat_b, $this->author_two, 'publish' ),
			array( 'Elder wine', $this->cat_b, $this->author_one, 'publish' ),
			array( 'Hidden draft', $this->cat_a, $this->author_two, 'draft' ),
			array( 'Hidden private', $this->cat_a, $this->author_two, 'private' ),
		);
		foreach ( $posts as $index => list( $title, $category, $author, $status ) ) {
			self::factory()->post->create(
				array(
					'post_title'    => $title,
					'post_category' => array( $category ),
					'post_author'   => $author,
					'post_status'   => $status,
					'post_date'     => '2026-01-0' . ( $index + 1 ) . ' 10:00:00',
				)
			);
		}
		self::factory()->post->create( array( 'post_title' => 'Hidden protected', 'post_category' => array( $this->cat_a ), 'post_password' => 'secret', 'post_author' => $this->author_two ) );
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
	 * @param array<string, mixed> $overrides Overrides of the view document.
	 */
	private function view( array $overrides = array() ): int {
		$base = array(
			'query'   => array( 'per_page' => 10, 'orderby' => 'title', 'order' => 'asc' ),
			'filters' => array(
				array( 'type' => 'taxonomy', 'taxonomy' => 'category', 'display' => 'buttons' ),
				array( 'type' => 'author', 'display' => 'dropdown' ),
				array( 'type' => 'search' ),
				array( 'type' => 'sort', 'options' => array( 'title', 'date' ) ),
			),
		);
		// Filters are replaced as a whole: merging lists index by index would mix the filters of the two definitions.
		$filters = $overrides['filters'] ?? $base['filters'];
		unset( $overrides['filters'] );
		$document            = array_replace_recursive( $base, $overrides );
		$document['filters'] = $filters;
		return $this->services->views()->save( null, $this->view_document( $document ) );
	}

	/**
	 * @param array<string, mixed> $filters Raw filter input of the first instance.
	 */
	private function render( int $view_id, array $filters = array(), int $page = 1 ): string {
		$state = array();
		if ( array() !== $filters ) {
			$state['f'] = $filters;
		}
		if ( $page > 1 ) {
			$state['page'] = (string) $page;
		}
		$_GET = array() === $state ? array() : array( 'swp' => array( 'view-' . $view_id . '-1' => $state ) );
		$this->reset_rendering();
		return do_shortcode( '[sw_postlist id="' . $view_id . '"]' );
	}

	/**
	 * @return list<string>
	 */
	private function titles( string $html ): array {
		preg_match_all( '/<h3[^>]*class="swp-card__title"[^>]*>(?:<a[^>]*>)?([^<]+)/', $html, $found );
		if ( array() === $found[1] ) {
			preg_match_all( '/swp-card__title[^>]*>(?:<a[^>]*>)?([^<]+)/', $html, $found );
		}
		return array_map( 'html_entity_decode', $found[1] );
	}

	public function test_without_input_all_visible_entries_show_and_the_form_is_rendered(): void {
		$html = $this->render( $this->view() );
		$this->assertSame( array( 'Apple pie', 'Banana split', 'Cherry tart', 'Date cake', 'Elder wine' ), $this->titles( $html ) );
		$this->assertStringContainsString( '<form class="swp-filters" method="get"', $html );
		$this->assertStringNotContainsString( 'swp-filters__reset', $html, 'Nothing to reset yet.' );
		foreach ( array( 'Hidden draft', 'Hidden private', 'Hidden protected' ) as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html );
		}
	}

	public function test_taxonomy_author_search_and_sort_narrow_the_selection(): void {
		$view = $this->view();
		$this->assertSame( array( 'Apple pie', 'Banana split', 'Cherry tart' ), $this->titles( $this->render( $view, array( 'tax_category' => (string) $this->cat_a ) ) ) );
		$this->assertSame( array( 'Date cake', 'Elder wine' ), $this->titles( $this->render( $view, array( 'tax_category' => (string) $this->cat_b ) ) ) );
		$this->assertCount( 5, $this->titles( $this->render( $view, array( 'tax_category' => $this->cat_a . ',' . $this->cat_b ) ) ) );
		$this->assertSame( array( 'Apple pie', 'Cherry tart', 'Elder wine' ), $this->titles( $this->render( $view, array( 'author' => (string) $this->author_one ) ) ) );
		$this->assertSame( array( 'Apple pie', 'Cherry tart' ), $this->titles( $this->render( $view, array( 'tax_category' => (string) $this->cat_a, 'author' => (string) $this->author_one ) ) ), 'Filters combine as an intersection.' );
		$this->assertSame( array( 'Banana split' ), $this->titles( $this->render( $view, array( 'q' => 'banana' ) ) ) );
		$this->assertSame( array( 'Elder wine', 'Date cake', 'Cherry tart', 'Banana split', 'Apple pie' ), $this->titles( $this->render( $view, array( 'sort' => 'title:desc' ) ) ) );
		$this->assertSame( array( 'Apple pie', 'Banana split' ), array_slice( $this->titles( $this->render( $view, array( 'sort' => 'date:asc' ) ) ), 0, 2 ) );
	}

	public function test_hidden_content_stays_hidden_whatever_the_visitor_asks_for(): void {
		$view = $this->view();
		foreach ( array( 'Hidden', 'draft', 'private', 'protected' ) as $needle ) {
			$this->assertSame( array(), $this->titles( $this->render( $view, array( 'q' => $needle ) ) ), $needle );
		}
		$html = $this->render( $view, array( 'author' => (string) $this->author_two, 'tax_category' => (string) $this->cat_a ) );
		$this->assertSame( array( 'Banana split' ), $this->titles( $html ), 'The author also wrote a draft, a private and a protected entry.' );
	}

	public function test_unconfigured_or_unsupported_input_does_not_change_anything(): void {
		$only_search = $this->services->views()->save( null, $this->view_document( array( 'filters' => array( array( 'type' => 'search' ) ) ) ) );
		$html        = $this->render( $only_search, array( 'tax_category' => (string) $this->cat_b, 'author' => (string) $this->author_one, 'sort' => 'title:desc' ) );
		$this->assertCount( 5, $this->titles( $html ), 'Only the configured search filter is read.' );

		$pages = $this->services->views()->save( null, $this->view_document( array( 'source' => 'post:page', 'filters' => array( array( 'type' => 'taxonomy', 'taxonomy' => 'category' ) ) ) ) );
		$html  = $this->render( $pages, array( 'tax_category' => (string) $this->cat_a ) );
		$this->assertStringNotContainsString( 'swp-filters', $html, 'Pages have no category: the source does not support the filter.' );
	}

	public function test_totals_pages_and_links_stay_consistent_with_a_filter(): void {
		$view = $this->view( array( 'query' => array( 'per_page' => 2 ) ) );
		$page1 = $this->render( $view, array( 'tax_category' => (string) $this->cat_a ) );
		$this->assertSame( array( 'Apple pie', 'Banana split' ), $this->titles( $page1 ) );
		$this->assertSame( 1, substr_count( $page1, 'swp-pagination__link--next' ) );
		$this->assertStringContainsString( 'swp%5Bview-' . $view . '-1%5D%5Bf%5D%5Btax_category%5D=' . $this->cat_a, $page1, 'Page links keep the filter state.' );
		$this->assertStringContainsString( '%5Bpage%5D=2', $page1 );

		$page2 = $this->render( $view, array( 'tax_category' => (string) $this->cat_a ), 2 );
		$this->assertSame( array( 'Cherry tart' ), $this->titles( $page2 ) );
		$this->assertStringNotContainsString( 'swp-pagination__link--next', $page2 );

		$all = $this->render( $view );
		$this->assertSame( 3, substr_count( $all, 'class="swp-pagination__link' ) - substr_count( $all, 'swp-pagination__link--next' ) - substr_count( $all, 'swp-pagination__link--prev' ), 'Five entries on pages of two: three pages.' );
	}

	public function test_the_form_works_without_javascript_and_keeps_foreign_and_other_instance_state(): void {
		$view = $this->view();
		$_GET = array(
			'page_id' => '42',
			'utm'     => 'a b&c',
			'swp'     => array(
				'view-' . $view . '-1' => array( 'f' => array( 'tax_category' => (string) $this->cat_b, 'q' => 'x"><script>' ), 'page' => '2' ),
				'view-' . $view . '-2' => array( 'f' => array( 'q' => 'keep' ), 'page' => '3' ),
			),
		);
		$this->reset_rendering();
		$html = do_shortcode( '[sw_postlist id="' . $view . '"]' );

		$this->assertStringContainsString( 'action="#swp-view-' . $view . '-1"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page_id" value="42">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="utm" value="a b&amp;c">', $html );
		$this->assertStringContainsString( 'name="swp[view-' . $view . '-2][f][q]" value="keep"', $html, 'The other instance keeps its filter.' );
		$this->assertStringContainsString( 'name="swp[view-' . $view . '-2][page]" value="3"', $html, 'The other instance keeps its page.' );
		$this->assertStringNotContainsString( 'name="swp[view-' . $view . '-1][page]"', $html, 'A filter change leaves out the own page: back to page one.' );
		$this->assertStringContainsString( 'class="swp-filter__button is-active" name="swp[view-' . $view . '-1][f][tax_category]" value="' . $this->cat_b . '"', $html );
		$this->assertStringContainsString( 'aria-pressed="true"', $html );
		$this->assertStringContainsString( 'swp-filters__reset', $html );
		$this->assertStringNotContainsString( '<script>', $html, 'Search text cannot inject markup.' );
		$this->assertStringNotContainsString( 'value="x"', $html, 'The quote is escaped inside the attribute.' );
		$this->assertStringContainsString( 'maxlength="100"', $html );
	}

	public function test_filter_fields_render_labels_options_and_all_displays(): void {
		$view = $this->view(
			array(
				'filters' => array(
					array( 'type' => 'taxonomy', 'taxonomy' => 'category', 'display' => 'checkboxes', 'label' => 'Topics' ),
					array( 'type' => 'author', 'display' => 'buttons' ),
					array( 'type' => 'search', 'label' => 'Find', 'placeholder' => 'Type here' ),
					array( 'type' => 'sort', 'options' => array( 'title' ) ),
				),
			)
		);
		$html = $this->render( $view, array( 'tax_category' => array( (string) $this->cat_a, (string) $this->cat_b ) ) );
		$this->assertStringContainsString( '<legend class="swp-filter__label">Topics</legend>', $html );
		$this->assertSame( 2, substr_count( $html, 'type="checkbox"' ) );
		$this->assertSame( 2, substr_count( $html, ' checked' ) );
		$this->assertStringContainsString( 'Ann Author', $html );
		$this->assertStringContainsString( 'swp-filter--buttons', $html );
		$this->assertStringContainsString( 'placeholder="Type here"', $html );
		$this->assertStringContainsString( '>Find</label>', $html );
		$this->assertStringContainsString( '>Title, ascending</option>', $html );

		$subset = $this->view( array( 'filters' => array( array( 'type' => 'taxonomy', 'taxonomy' => 'category', 'terms' => array( $this->cat_b ), 'display' => 'dropdown' ) ) ) );
		$html   = $this->render( $subset, array( 'tax_category' => (string) $this->cat_a ) );
		$this->assertStringContainsString( 'Beta cat', $html );
		$this->assertStringNotContainsString( 'Alpha cat', $html, 'Only the configured terms are offered.' );
		$this->assertCount( 5, $this->titles( $html ), 'A term outside the configured subset is not accepted.' );
	}

	public function test_container_placeholder_places_the_filters_otherwise_they_come_first(): void {
		$this->admin();
		$template = $this->services->templates()->save( null, $this->template_document( '<section class="mine"><header>{{view.filters}}</header>{{view.entries}}{{view.navigation}}</section>', \SW\Postlist\Domain\Template\TemplateRole::Container, 'Own container' ), 'publish' );
		$view     = $this->view( array( 'templates' => array( 'container' => $template ) ) );
		$html     = $this->render( $view );
		$this->assertMatchesRegularExpression( '/<header><form class="swp-filters"/', $html );
		$this->assertSame( 1, substr_count( $html, '<form class="swp-filters"' ) );

		$default = $this->render( $this->view() );
		$this->assertLessThan( strpos( $default, 'class="swp-grid"' ), strpos( $default, '<form class="swp-filters"' ), 'Without the placeholder the filters come before the entries.' );
	}

	public function test_third_party_filter_types_parse_render_and_are_contained(): void {
		add_filter(
			'sw_postlist_filter_types',
			static function ( array $types ): array {
				$types[] = new FilterTypeDefinition(
					'fruit',
					'Fruit',
					array( 'label' => array( 'type' => 'string', 'null' => true ) ),
					static fn ( array $config, array $values ): array => array( 'search' => $values[0] ?? null ),
					static fn ( array $config, array $values, string $name, string $id ): string => '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $values[0] ?? '' ) . '">'
				);
				$types[] = new FilterTypeDefinition( 'boom', 'Boom', array(), static function (): array {
					throw new RuntimeException( 'secret' );
				}, static function (): string {
					throw new RuntimeException( 'secret' );
				} );
				return $types;
			}
		);
		Services::reset();
		$this->services = Services::instance();
		$view           = $this->view( array( 'filters' => array( array( 'type' => 'fruit' ), array( 'type' => 'boom' ) ) ) );
		$html           = $this->render( $view, array( 'fruit' => 'cherry', 'boom' => 'x' ) );
		$this->assertSame( array( 'Cherry tart' ), $this->titles( $html ) );
		$this->assertStringContainsString( 'name="swp[view-' . $view . '-1][f][fruit]" value="cherry"', $html );
		$this->assertStringNotContainsString( 'secret', $html );
	}

	public function test_query_hook_and_visitor_filter_reduce_results_together(): void {
		$cat = $this->cat_a;
		add_filter(
			'sw_postlist_query_args',
			static function ( array $args ) use ( $cat ): array {
				$args['category__in'] = array( $cat );
				return $args;
			}
		);
		$view = $this->view();
		$this->assertCount( 3, $this->titles( $this->render( $view ) ) );
		$this->assertSame( array( 'Apple pie', 'Cherry tart' ), $this->titles( $this->render( $view, array( 'author' => (string) $this->author_one ) ) ) );
		$this->assertSame( array(), $this->titles( $this->render( $view, array( 'tax_category' => (string) $this->cat_b ) ) ), 'A visitor filter never widens beyond the hook.' );
	}

	public function test_garbage_input_never_breaks_rendering(): void {
		$view    = $this->view();
		$garbage = array(
			array( 'tax_category' => array( array( array( 'deep' ) ) ) ),
			array( 'q' => str_repeat( "\xff\xfe", 300 ) ),
			array( 'sort' => array( 'a' => array( 'b' ) ), 'author' => PHP_INT_MAX ),
			array( 'tax_category' => '1,2,,3,-4,x', 'author' => '0', 'q' => "\0\n\t" ),
			array( str_repeat( 'k', 500 ) => 'v', '' => 'v' ),
		);
		foreach ( $garbage as $filters ) {
			$html = $this->render( $view, $filters );
			$this->assertStringContainsString( 'swp-view', $html );
			$this->assertStringNotContainsString( 'Fatal', $html );
		}
		$_GET = array( 'swp' => 'string', 'x' => array() );
		$this->reset_rendering();
		$this->assertStringContainsString( 'swp-view', do_shortcode( '[sw_postlist id="' . $view . '"]' ) );
	}

	public function test_filters_are_part_of_the_stored_document_and_survive_overrides(): void {
		$view     = $this->view();
		$document = $this->services->views()->find( $view );
		$this->assertCount( 4, $document?->filters ?? array() );
		$this->assertArrayHasKey( 'filters', $document?->to_array() ?? array() );
		$plain = $this->services->views()->save( null, $this->view_document() );
		$this->assertArrayNotHasKey( 'filters', $this->services->views()->find( $plain )?->to_array() ?? array(), 'Documents without filters stay unchanged.' );
		$_GET = array();
		$this->reset_rendering();
		$html = do_shortcode( '[sw_postlist id="' . $view . '" per_page="2" layout="list"]' );
		$this->assertStringContainsString( 'swp-filters', $html );
	}

	public function test_landmarks_stay_unique_with_several_embeddings(): void {
		$view = $this->view( array( 'query' => array( 'per_page' => 2 ) ) );
		$this->reset_rendering();
		$html = do_shortcode( '[sw_postlist id="' . $view . '"] [sw_postlist id="' . $view . '"] [sw_postlist id="' . $view . '"]' );
		$this->assertSame( 1, substr_count( $html, 'aria-label="Filter"' ) );
		$this->assertSame( 1, substr_count( $html, 'aria-label="Filter 2"' ) );
		$this->assertSame( 1, substr_count( $html, 'aria-label="Filter 3"' ) );
		$this->assertSame( 1, substr_count( $html, 'aria-label="Pagination"' ) );
		$this->assertSame( 1, substr_count( $html, 'aria-label="Pagination 3"' ) );
	}

	/**
	 * @param array<string, mixed> $maps Maps of the settings.
	 */
	private function render_terms( string $template, array $maps ): string {
		$this->admin();
		$response = rest_do_request( ( static function () use ( $maps ): WP_REST_Request {
			$request = new WP_REST_Request( 'PUT', '/sw-postlist/v1/admin/settings' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'meta_keys' => array(), 'maps' => $maps ) ) );
			return $request;
		} )() );
		$this->assertSame( 200, $response->get_status() );
		Services::reset();
		$this->services = Services::instance();
		$entry          = $this->services->templates()->save( null, $this->template_document( $template, \SW\Postlist\Domain\Template\TemplateRole::Entry, 'Terms' ) );
		$view           = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $entry ) ) ) );
		wp_set_current_user( 0 );
		return $this->render( $view );
	}

	public function test_the_map_formatter_hides_renames_and_strips_prefixes_before_any_list_formatter(): void {
		$kategorie = self::factory()->category->create( array( 'name' => 'Kategorie Feldstraße', 'slug' => 'k-feld' ) );
		$post      = self::factory()->post->create( array( 'post_title' => 'Mapped post', 'post_category' => array( $kategorie, 1 ) ) );
		$this->assertNotEmpty( $post );
		$maps = array( 'cats' => array( 'hide' => array( 'uncategorized' ), 'labels' => array(), 'strip_prefix' => 'Kategorie ' ) );

		$chips = $this->render_terms( '<p class="t">{{entry.terms.category|map:"cats"|chips}}</p>', $maps );
		$this->assertStringContainsString( '<span class="swp-chip">Feldstraße</span>', $chips );
		$this->assertStringNotContainsString( 'Uncategorized', $chips );
		$this->assertStringNotContainsString( 'Kategorie Feldstraße', $chips );

		$this->assertStringContainsString( 'Feldstraße', $this->render_terms( '<p class="t">{{entry.terms.category|map:"cats"|join:" / "}}</p>', $maps ) );
		$linked = $this->render_terms( '<p class="t">{{entry.terms.category|map:"cats"|link}}</p>', $maps );
		$this->assertMatchesRegularExpression( '/<a href="[^"]+">Feldstraße<\/a>/', $linked );
		$this->assertStringNotContainsString( 'Uncategorized', $linked );

		$by_slug = $this->render_terms( '<p class="t">{{entry.terms.category|map:"cats"|join}}</p>', array( 'cats' => array( 'hide' => array( 'k-feld' ), 'labels' => array( 'uncategorized' => 'Allgemein' ), 'strip_prefix' => '' ) ) );
		$this->assertStringContainsString( 'Allgemein', $by_slug );
		$this->assertStringNotContainsString( 'Feldstraße', $by_slug );

		$without = $this->render_terms( '<p class="t">{{entry.terms.category|join}}</p>', $maps );
		$this->assertStringContainsString( 'Kategorie Feldstraße', $without, 'Without the formatter nothing changes.' );
	}

	public function test_an_unknown_map_is_a_template_error_and_maps_survive_the_settings_roundtrip(): void {
		$this->admin();
		$request = new WP_REST_Request( 'PUT', '/sw-postlist/v1/admin/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'meta_keys' => array(), 'maps' => array( 'Bad Name' => array( 'hide' => array( 'x' ) ), 'cats' => array( 'hide' => array( 'x', 'X' ), 'labels' => array( 'a' => 'b' ), 'strip_prefix' => 'P ' ) ) ) ) );
		$saved = rest_do_request( $request )->get_data();
		$this->assertSame( array( 'cats' ), array_keys( $saved['maps'] ) );
		$this->assertCount( 1, $saved['maps']['cats']['hide'], 'Case-insensitive duplicates collapse.' );

		$meta_only = new WP_REST_Request( 'PUT', '/sw-postlist/v1/admin/settings' );
		$meta_only->set_header( 'content-type', 'application/json' );
		$meta_only->set_body( (string) wp_json_encode( array( 'meta_keys' => array() ) ) );
		$this->assertSame( array( 'cats' ), array_keys( rest_do_request( $meta_only )->get_data()['maps'] ), 'Saving only meta keys keeps the maps.' );

		Services::reset();
		$services = Services::instance();
		$source   = $services->sources()->get( 'post:post' );
		foreach ( array( 'cats' => true, 'typo' => false ) as $name => $valid ) {
			$document = new \SW\Postlist\Domain\Template\TemplateDocument( 'x', \SW\Postlist\Domain\Template\TemplateRole::Entry, '<p>{{entry.terms.category|map:"' . $name . '"|chips}}</p>', '' );
			$this->assertSame( $valid, $services->template_compilation()->validate( $document, $source )->is_valid(), $name );
		}
		$chain = new \SW\Postlist\Domain\Template\TemplateDocument( 'x', \SW\Postlist\Domain\Template\TemplateRole::Entry, '<p>{{entry.title|map:"cats"}}</p>', '' );
		$this->assertFalse( $services->template_compilation()->validate( $chain, $source )->is_valid(), 'map works on lists only.' );
	}
}
