<?php
/**
 * Storage of views and templates in the internal post types (ADR-02).
 *
 * @package sw-postlist
 */

use SW\Postlist\Application\Port\DocumentRepositoryException;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\PostTypes;

class PersistenceTest extends SWP_TestCase {

	public function test_internal_types_are_hidden_from_public_access_and_the_source_list(): void {
		foreach ( array( PostTypes::VIEW, PostTypes::TEMPLATE ) as $type ) {
			$object = get_post_type_object( $type );
			$this->assertFalse( $object->public );
			$this->assertFalse( $object->publicly_queryable );
			$this->assertTrue( $object->exclude_from_search );
			$this->assertFalse( $object->show_in_rest );
			$this->assertNotContains( $type, get_post_types( array( 'public' => true ) ) );
			$this->assertArrayNotHasKey( 'post:' . $type, $this->services->sources()->all() );
		}
	}

	public function test_template_roundtrip_keeps_html_quotes_backslashes_and_unicode(): void {
		$html = '<a href="x" title=\'it\\\'s\' data-p="C:\\\\dir\\n">„Ümläut“ \\ {{entry.title}}</a>';
		$id   = $this->services->templates()->save( null, $this->template_document( $html ) );

		$found = $this->services->templates()->find( $id );
		$this->assertNotNull( $found );
		$this->assertSame( $html, $found->html );
		$this->assertSame( '.swp-card{color:red}', $found->css );
	}

	public function test_template_restore_from_revision_keeps_special_characters(): void {
		$first  = '<p title="a\\b">"one" \\ {{entry.title}}</p>';
		$second = '<p title="c\\d">"two" \\\\ {{entry.title}}</p>';
		$id     = $this->services->templates()->save( null, $this->template_document( $first ) );
		$this->services->templates()->save( $id, $this->template_document( $second, TemplateRole::Entry, 'Card v2' ) );

		$revisions = wp_get_post_revisions( $id );
		$this->assertNotEmpty( $revisions, 'WordPress keeps revisions of the template.' );
		$this->assertSame( $second, $this->services->templates()->find( $id )->html );

		$oldest = end( $revisions );
		wp_restore_post_revision( $oldest->ID );

		$restored = $this->services->templates()->find( $id );
		$this->assertNotNull( $restored );
		$this->assertSame( $first, $restored->html );
	}

	public function test_view_roundtrip_and_template_references(): void {
		$container = $this->services->templates()->save( null, $this->template_document( '<ul>{{view.entries}}</ul>', TemplateRole::Container, 'Container' ) );
		$entry     = $this->services->templates()->save( null, $this->template_document() );
		$view_id   = $this->services->views()->save(
			null,
			$this->view_document( array( 'templates' => array( 'container' => $container, 'entry' => $entry ), 'query' => array( 'per_page' => 7 ) ) )
		);

		$found = $this->services->views()->find( $view_id );
		$this->assertNotNull( $found );
		$this->assertSame( 7, $found->query->per_page );
		$this->assertSame( array( $container, $entry ), $found->template_ids() );
		$this->assertSame( array( $view_id ), $this->services->views()->view_ids_using_template( $entry ) );
	}

	public function test_view_rejects_missing_template_and_wrong_role(): void {
		$entry_template = $this->services->templates()->save( null, $this->template_document() );
		try {
			$this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => 999999 ) ) ) );
			$this->fail( 'A missing template must be rejected.' );
		} catch ( DocumentRepositoryException $error ) {
			$this->assertSame( 'template_missing', $error->error_code );
		}
		try {
			$this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'container' => $entry_template ) ) ) );
			$this->fail( 'A wrong role must be rejected.' );
		} catch ( DocumentRepositoryException $error ) {
			$this->assertSame( 'template_role_mismatch', $error->error_code );
		}
	}

	public function test_view_status_and_unknown_ids_are_rejected(): void {
		try {
			$this->services->views()->save( null, $this->view_document(), 'private' );
			$this->fail( 'Unknown status.' );
		} catch ( DocumentRepositoryException $error ) {
			$this->assertSame( 'status_invalid', $error->error_code );
		}
		$post_id = self::factory()->post->create();
		$this->expectException( DocumentRepositoryException::class );
		$this->services->views()->save( $post_id, $this->view_document() );
	}

	public function test_corrupt_or_foreign_documents_are_not_returned(): void {
		$view_id = $this->services->views()->save( null, $this->view_document() );
		update_post_meta( $view_id, PostTypes::META_DOCUMENT, 'not json' );
		$this->assertNull( $this->services->views()->find( $view_id ) );
		$this->assertNull( $this->services->views()->find( self::factory()->post->create() ) );
		$this->assertNull( $this->services->templates()->find( $view_id ) );
	}

	public function test_used_template_is_protected_on_every_route(): void {
		$template = $this->services->templates()->save( null, $this->template_document() );
		$view_id  = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $template ) ) ) );

		$this->assertFalse( wp_trash_post( $template ) );
		$this->assertFalse( wp_delete_post( $template, true ) );
		$this->assertSame( 'publish', get_post_status( $template ) );

		// The WordPress admin routes end up in the same functions.
		$this->admin();
		$this->assertTrue( current_user_can( 'delete_post', $template ) );
		$this->assertFalse( wp_delete_post( $template, true ) );

		// Re-assign, then the template is free.
		$other = $this->services->templates()->save( null, $this->template_document( '<b>{{entry.title}}</b>', TemplateRole::Entry, 'Other' ) );
		$this->services->views()->save( $view_id, $this->view_document( array( 'templates' => array( 'entry' => $other ) ) ) );
		$this->assertSame( array(), $this->services->views()->view_ids_using_template( $template ) );
		$this->assertNotFalse( wp_trash_post( $template ) );
	}

	public function test_untrashed_view_loses_references_to_removed_templates(): void {
		$template = $this->services->templates()->save( null, $this->template_document() );
		$view_id  = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $template ) ) ) );

		wp_trash_post( $view_id );
		$this->assertSame( array(), $this->services->views()->view_ids_using_template( $template ) );
		$this->assertNotFalse( wp_delete_post( $template, true ), 'A trashed view does not keep the template alive.' );

		wp_untrash_post( $view_id );
		wp_update_post( array( 'ID' => $view_id, 'post_status' => 'publish' ) );
		$found = $this->services->views()->find( $view_id );
		$this->assertNotNull( $found );
		$this->assertNull( $found->entry_template_id );
	}

	public function test_restored_view_revision_realigns_references(): void {
		$template_a = $this->services->templates()->save( null, $this->template_document( '<i>{{entry.title}}</i>', TemplateRole::Entry, 'A' ) );
		$template_b = $this->services->templates()->save( null, $this->template_document( '<b>{{entry.title}}</b>', TemplateRole::Entry, 'B' ) );
		$view_id    = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $template_a ) ) ) );
		$this->services->views()->save( $view_id, $this->view_document( array( 'title' => 'Renamed', 'templates' => array( 'entry' => $template_b ) ) ) );

		$revisions = wp_get_post_revisions( $view_id );
		$this->assertNotEmpty( $revisions );
		wp_restore_post_revision( end( $revisions )->ID );

		$found = $this->services->views()->find( $view_id );
		$this->assertNotNull( $found );
		$this->assertSame( array( $view_id ), $this->services->views()->view_ids_using_template( $found->entry_template_id ?? 0 ) );
	}

	public function test_only_managers_have_object_capabilities(): void {
		$template = $this->services->templates()->save( null, $this->template_document() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( current_user_can( 'edit_post', $template ) );
		$this->assertFalse( current_user_can( 'delete_post', $template ) );

		$this->admin();
		$this->assertTrue( current_user_can( 'edit_post', $template ) );
		$this->assertTrue( current_user_can( 'delete_post', $template ) );
		$this->assertTrue( current_user_can( 'manage_options' ), 'The management capability stays a plain capability.' );
		$this->assertTrue( current_user_can( 'edit_swp_views' ) );
	}

	public function test_manage_options_is_not_turned_into_a_meta_capability(): void {
		global $post_type_meta_caps;
		$this->assertArrayNotHasKey( 'manage_options', $post_type_meta_caps );
	}
}
