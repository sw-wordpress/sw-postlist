<?php
/**
 * Admin menu, access control and asset registration.
 *
 * @package sw-postlist
 */

use SW\Postlist\Presentation\Admin\AdminPage;

class AdminPageTest extends WP_UnitTestCase {

	public function test_menu_page_requires_the_capability(): void {
		global $menu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new AdminPage();
		$page->add_menu();

		$slugs = array_column( $menu, 2 );
		$this->assertContains( AdminPage::SLUG, $slugs );
	}

	public function test_render_denies_users_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );

		( new AdminPage() )->render();
	}

	public function test_render_prints_mount_node_for_administrators(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$built = is_readable( SW_POSTLIST_DIR . 'assets/admin/app.js' );

		ob_start();
		( new AdminPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( $built ? 'id="sw-postlist-app"' : 'has not been built', $html );
	}

	public function test_config_matches_the_spa_contract(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$config = ( new AdminPage() )->config();

		$this->assertSame(
			array( 'restRoot', 'nonce', 'locale', 'version', 'adminUrl', 'siteUrl', 'pluginUrl' ),
			array_keys( $config )
		);
		$this->assertStringContainsString( 'sw-postlist/v1/', $config['restRoot'] );
		$this->assertSame( SW_POSTLIST_VERSION, $config['version'] );
	}

	public function test_enqueue_registers_versioned_assets_only_on_the_plugin_screen(): void {
		if ( ! is_readable( SW_POSTLIST_DIR . 'assets/admin/app.js' ) ) {
			$this->markTestSkipped( 'Admin bundle not built.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new AdminPage();
		$page->add_menu();

		$page->enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'sw-postlist-admin', 'enqueued' ) );

		$hook = get_plugin_page_hookname( AdminPage::SLUG, '' );
		$page->enqueue( $hook );
		$this->assertTrue( wp_script_is( 'sw-postlist-admin', 'enqueued' ) );
		$this->assertSame( SW_POSTLIST_VERSION, wp_scripts()->registered['sw-postlist-admin']->ver );
	}

	public function test_script_is_loaded_as_module_only_for_the_plugin_handle(): void {
		$page = new AdminPage();
		$this->assertStringContainsString( 'type="module"', $page->module_tag( '<script src="x"></script>', 'sw-postlist-admin' ) );
		$this->assertSame( '<script src="x"></script>', $page->module_tag( '<script src="x"></script>', 'other' ) );
	}
}
