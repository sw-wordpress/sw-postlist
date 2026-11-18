<?php
/**
 * M8: JSON export/import, duplicating, and the template references that must stay correct afterwards.
 *
 * @package sw-postlist
 */

use SW\Postlist\Infrastructure\WordPress\PostTypes;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;

class PackageTest extends SWP_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->admin();
		global $wp_rest_server;
		$wp_rest_server = null;
		InstanceRegistry::reset();
		ViewRenderer::reset();
		( new PostlistShortcode( $this->services->renderer() ) )->register();
	}

	public function tear_down(): void {
		InstanceRegistry::reset();
		ViewRenderer::reset();
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
	 * Two views that share one entry template and one view with its own container.
	 *
	 * @return array{news: int, events: int, entry: int, container: int}
	 */
	private function seed(): array {
		$container = $this->services->templates()->save( null, $this->template_document( '<section class="frame">{{view.entries}}{{view.navigation}}</section>', \SW\Postlist\Domain\Template\TemplateRole::Container, 'Frame' ) );
		$entry     = $this->services->templates()->save( null, $this->template_document( '<article class="swp-card__title">{{entry.title|upper}}</article>', \SW\Postlist\Domain\Template\TemplateRole::Entry, 'Shared card' ) );
		$news      = $this->services->views()->save( null, $this->view_document( array( 'title' => 'News', 'templates' => array( 'container' => $container, 'entry' => $entry ), 'filters' => array( array( 'type' => 'search' ) ) ) ) );
		$events    = $this->services->views()->save( null, $this->view_document( array( 'title' => 'Events', 'layout' => array( 'type' => 'list' ), 'templates' => array( 'entry' => $entry ) ) ), 'draft' );
		return array(
			'news'      => $news,
			'events'    => $events,
			'entry'     => $entry,
			'container' => $container,
		);
	}

	/**
	 * @return list<string>
	 */
	private function titles( int $view_id ): array {
		InstanceRegistry::reset();
		ViewRenderer::reset();
		preg_match_all( '/swp-card__title">([^<]+)/', do_shortcode( '[sw_postlist id="' . $view_id . '"]' ), $found );
		return $found[1];
	}

	public function test_export_route_returns_the_package_and_refuses_empty_or_missing_selections(): void {
		$ids      = $this->seed();
		$response = $this->call( 'POST', '/admin/export', array( 'views' => array( $ids['news'], $ids['events'] ), 'presets' => array( 'builder-card' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$package = json_decode( (string) wp_json_encode( $response->get_data()['package'] ), true );
		$this->assertSame( 'sw-postlist-package', $package['format'] );
		$this->assertSame( array( 't1', 't2' ), array_keys( $package['templates'] ), 'Container and the shared entry template, once.' );
		$this->assertSame( array( 'builder-card' ), array_column( $package['presets'], 'id' ) );
		$this->assertSame( SW_POSTLIST_VERSION, $package['plugin_version'] );
		$this->assertStringNotContainsString( '"entry":' . $ids['entry'] . ',', (string) wp_json_encode( $package ), 'No local IDs.' );

		$this->assertSame( 422, $this->call( 'POST', '/admin/export', array() )->get_status() );
		$missing = $this->call( 'POST', '/admin/export', array( 'views' => array( 999999 ), 'presets' => array( 'ghost' ) ) );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( array( 'view:999999', 'preset:ghost' ), $missing->get_data()['missing'] );
	}

	public function test_roundtrip_to_a_second_installation_renders_the_same(): void {
		$ids = $this->seed();
		$this->services->views()->save( $ids['events'], $this->services->views()->find( $ids['events'] ), 'publish' );
		$this->create_posts( 3 );
		$before  = array( $this->titles( $ids['news'] ), $this->titles( $ids['events'] ) );
		$package = $this->call( 'POST', '/admin/export', array( 'views' => array( $ids['news'], $ids['events'] ) ) )->get_data()['package'];
		$package = json_decode( (string) wp_json_encode( $package ), true );

		// "Second installation": nothing of the originals exists any more.
		foreach ( array( $ids['news'], $ids['events'] ) as $view_id ) {
			wp_delete_post( $view_id, true );
		}
		wp_delete_post( $ids['entry'], true );
		wp_delete_post( $ids['container'], true );

		$report = $this->call( 'POST', '/admin/import', array( 'package' => $package ) );
		$this->assertSame( 200, $report->get_status(), (string) wp_json_encode( $report->get_data() ) );
		$created = $report->get_data()['created'];
		$this->assertCount( 2, $created['views'] );
		$this->assertCount( 2, $created['templates'] );
		$this->assertSame( array(), $report->get_data()['renamed'], 'No collisions on a clean site.' );
		$this->assertSame( $before, array( $this->titles( $created['views'][0] ), $this->titles( $created['views'][1] ) ) );
		$this->assertSame( 'publish', get_post_status( $created['views'][1] ), 'Both were published in the original.' );
		$this->assertSame( 'News', get_the_title( $created['views'][0] ) );
		$this->assertStringContainsString( 'swp-filters', do_shortcode( '[sw_postlist id="' . $created['views'][0] . '"]' ), 'The filters travel with the view.' );
	}

	public function test_import_keeps_the_where_used_index_exact(): void {
		$ids     = $this->seed();
		$package = json_decode( (string) wp_json_encode( $this->call( 'POST', '/admin/export', array( 'views' => array( $ids['news'], $ids['events'] ) ) )->get_data()['package'] ), true );
		$report  = $this->call( 'POST', '/admin/import', array( 'package' => $package ) )->get_data();
		$views   = $this->services->views();
		$entry   = $this->services->views()->find( $report['created']['views'][0] )->entry_template_id;

		$this->assertNotSame( $ids['entry'], $entry, 'New local IDs.' );
		$this->assertEqualsCanonicalizing( $report['created']['views'], $views->view_ids_using_template( $entry ), 'Both imported views share the imported template.' );
		$this->assertEqualsCanonicalizing( array( $ids['news'], $ids['events'] ), $views->view_ids_using_template( $ids['entry'] ), 'The originals still use theirs.' );
		$template_titles = array_column( $this->services->templates()->summaries(), 'title' );
		$this->assertContains( 'Frame (2)', $template_titles );
		$this->assertContains( 'Shared card (2)', $template_titles );
		$this->assertContains( 'News (2)', array_column( $views->summaries(), 'title' ) );
		$this->assertFalse( $this->services->templates()->trash( $entry ), 'A template in use cannot be trashed.' );
	}

	public function test_dry_run_reports_without_writing_and_faulty_packages_get_clear_errors(): void {
		$ids     = $this->seed();
		$package = json_decode( (string) wp_json_encode( $this->call( 'POST', '/admin/export', array( 'views' => array( $ids['news'] ) ) )->get_data()['package'] ), true );
		$count   = static fn (): int => (int) ( wp_count_posts( PostTypes::VIEW )->publish + wp_count_posts( PostTypes::VIEW )->draft );
		$views   = $count();

		$dry = $this->call( 'POST', '/admin/import', array( 'package' => $package, 'dry_run' => true ) );
		$this->assertSame( 200, $dry->get_status() );
		$this->assertTrue( $dry->get_data()['dry_run'] );
		$this->assertSame( array( 'views' => 1, 'templates' => 2, 'presets' => 0 ), $dry->get_data()['counts'] );
		$this->assertSame( $views, $count() );

		$package['templates']['t2']['html'] = '<p>{{entry.nope}}</p>';
		$bad = $this->call( 'POST', '/admin/import', array( 'package' => $package ) );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertContains( 'unknown_placeholder', array_column( $bad->get_data()['errors'], 'code' ) );
		$this->assertSame( $views, $count(), 'Nothing was created.' );

		$package['format_version'] = 7;
		$this->assertContains( 'package_version_unsupported', array_column( $this->call( 'POST', '/admin/import', array( 'package' => $package ) )->get_data()['errors'], 'code' ) );
		$this->assertContains( 'package_format_invalid', array_column( $this->call( 'POST', '/admin/import', array( 'package' => 'x' ) )->get_data()['errors'], 'code' ) );
		$this->assertContains( 'package_format_invalid', array_column( $this->call( 'POST', '/admin/import', array() )->get_data()['errors'], 'code' ) );
	}

	public function test_oversized_packages_are_refused(): void {
		$huge = array( 'package' => array( 'format' => 'sw-postlist-package', 'format_version' => 1, 'pad' => str_repeat( 'x', 2200000 ) ) );
		$this->assertSame( 413, $this->call( 'POST', '/admin/import', $huge )->get_status() );
	}

	public function test_importing_a_preset_never_overwrites_an_existing_one(): void {
		$package = $this->call( 'POST', '/admin/export', array( 'presets' => array( 'builder-card' ) ) )->get_data()['package'];
		$package = json_decode( (string) wp_json_encode( $package ), true );
		$report  = $this->call( 'POST', '/admin/import', array( 'package' => $package ) )->get_data();
		$this->assertSame( array( 'builder-card-2' ), $report['created']['presets'] );
		$ids = array_column( $this->call( 'GET', '/admin/presets' )->get_data()['presets'], 'id' );
		$this->assertContains( 'builder-card', $ids );
		$this->assertContains( 'builder-card-2', $ids );
	}

	public function test_duplicating_a_view_shares_its_templates_and_stays_a_draft(): void {
		$ids  = $this->seed();
		$copy = $this->call( 'POST', '/admin/views/' . $ids['news'] . '/duplicate' );
		$this->assertSame( 201, $copy->get_status() );
		$data = $copy->get_data();
		$this->assertSame( 'draft', $data['status'] );
		$this->assertNotSame( $ids['news'], $data['id'] );
		$this->assertSame( 'News (copy)', get_the_title( $data['id'] ) );
		$this->assertSame( 'draft', get_post_status( $data['id'] ) );
		$stored = $this->services->views()->find( $data['id'] );
		$this->assertSame( $ids['entry'], $stored->entry_template_id );
		$this->assertSame( $ids['container'], $stored->container_template_id );
		$this->assertCount( 1, $stored->filters );
		$this->assertEqualsCanonicalizing( array( $ids['news'], $ids['events'], $data['id'] ), $this->services->views()->view_ids_using_template( $ids['entry'] ) );
		$this->assertSame( 'News (copy) 2', get_the_title( $this->call( 'POST', '/admin/views/' . $ids['news'] . '/duplicate' )->get_data()['id'] ) );
		$this->assertSame( 404, $this->call( 'POST', '/admin/views/999999/duplicate' )->get_status() );
	}

	public function test_duplicating_a_template_creates_an_independent_copy(): void {
		$ids  = $this->seed();
		$copy = $this->call( 'POST', '/admin/templates/' . $ids['entry'] . '/duplicate' );
		$this->assertSame( 201, $copy->get_status() );
		$new = $copy->get_data()['id'];
		$this->assertSame( 'Shared card (copy)', get_the_title( $new ) );
		$this->assertSame( array(), $this->services->views()->view_ids_using_template( $new ), 'Nothing uses the copy yet.' );
		$this->assertSame( $this->services->templates()->find( $ids['entry'] )->html, $this->services->templates()->find( $new )->html );
		$this->assertSame( 404, $this->call( 'POST', '/admin/templates/999999/duplicate' )->get_status() );
	}

	public function test_restoring_a_revision_brings_the_template_references_back(): void {
		$ids      = $this->seed();
		$other    = $this->services->templates()->save( null, $this->template_document( '<p class="swp-card__title">{{entry.title}}</p>', \SW\Postlist\Domain\Template\TemplateRole::Entry, 'Other card' ) );
		$original = $this->services->views()->find( $ids['news'] );
		$changed  = new \SW\Postlist\Domain\View\ViewDocument( $original->title, $original->source_id, $original->query, $original->layout, $original->pagination, $original->container_template_id, $other, $original->filters );
		$this->services->views()->save( $ids['news'], $changed );
		$this->assertContains( $ids['news'], $this->services->views()->view_ids_using_template( $other ) );
		$this->assertNotContains( $ids['news'], $this->services->views()->view_ids_using_template( $ids['entry'] ) );

		$revisions = wp_get_post_revisions( $ids['news'] );
		$this->assertNotEmpty( $revisions );
		$restore = null;
		foreach ( $revisions as $revision ) {
			if ( false !== strpos( (string) get_post_meta( $revision->ID, '_swp_document', true ), '"entry":' . $ids['entry'] ) ) {
				$restore = $revision;
				break;
			}
		}
		$this->assertNotNull( $restore, 'A revision with the earlier reference exists.' );
		wp_restore_post_revision( $restore->ID );
		$this->assertContains( $ids['news'], $this->services->views()->view_ids_using_template( $ids['entry'] ), 'The where-used index follows the restored document.' );
		$this->assertNotContains( $ids['news'], $this->services->views()->view_ids_using_template( $other ) );
		$this->assertEqualsCanonicalizing( array( $ids['news'], $ids['events'] ), $this->services->views()->view_ids_using_template( $ids['entry'] ) );
	}
}
