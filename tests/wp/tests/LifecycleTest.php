<?php
/**
 * Activation, deactivation and uninstall cleanup.
 *
 * @package sw-postlist
 */

use SW\Postlist\Infrastructure\WordPress\DataCleaner;
use SW\Postlist\Lifecycle;

class LifecycleTest extends WP_UnitTestCase {

	public function test_plugin_boots_and_loads_textdomain_hook(): void {
		$this->assertTrue( class_exists( \SW\Postlist\Plugin::class ) );
		$this->assertNotFalse( has_action( 'init', array( \SW\Postlist\Plugin::instance(), 'load_textdomain' ) ) );
	}

	public function test_activation_stores_version(): void {
		delete_option( 'sw_postlist_version' );
		Lifecycle::activate();
		$this->assertSame( SW_POSTLIST_VERSION, get_option( 'sw_postlist_version' ) );
	}

	public function test_cleanup_removes_only_postlist_data(): void {
		foreach ( DataCleaner::POST_TYPES as $post_type ) {
			register_post_type( $post_type );
		}
		$own_ids = array();
		foreach ( DataCleaner::POST_TYPES as $post_type ) {
			$own_ids[] = self::factory()->post->create( array( 'post_type' => $post_type ) );
		}
		$foreign_post = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_option( 'sw_postlist_version', '1.0.0' );
		set_transient( 'sw_postlist_ast_x', 'a', HOUR_IN_SECONDS );
		update_option( 'sw_postlistx_foreign', 'keep' );
		update_option( 'other_plugin_option', 'keep' );
		set_transient( 'other_plugin_transient', 'keep', HOUR_IN_SECONDS );

		( new DataCleaner() )->clean();

		foreach ( $own_ids as $own_id ) {
			$this->assertNull( get_post( $own_id ) );
		}
		$this->assertNotNull( get_post( $foreign_post ) );
		$this->assertFalse( get_option( 'sw_postlist_version' ) );
		$this->assertFalse( get_transient( 'sw_postlist_ast_x' ) );
		$this->assertSame( 'keep', get_option( 'sw_postlistx_foreign' ) );
		$this->assertSame( 'keep', get_option( 'other_plugin_option' ) );
		$this->assertSame( 'keep', get_transient( 'other_plugin_transient' ) );
	}

	public function test_uninstall_cleans_single_site_data(): void {
		update_option( 'sw_postlist_version', '1.0.0' );
		update_option( 'other_plugin_option', 'keep' );

		Lifecycle::uninstall();

		$this->assertFalse( get_option( 'sw_postlist_version' ) );
		$this->assertSame( 'keep', get_option( 'other_plugin_option' ) );
	}

	public function test_deactivation_keeps_data(): void {
		update_option( 'sw_postlist_version', '1.0.0' );

		Lifecycle::deactivate();

		$this->assertSame( '1.0.0', get_option( 'sw_postlist_version' ) );
	}

	public function test_services_share_one_clock_until_reset(): void {
		$first = \SW\Postlist\Services::instance()->clock();
		$this->assertSame( $first, \SW\Postlist\Services::instance()->clock() );

		\SW\Postlist\Services::reset();
		$this->assertNotSame( $first, \SW\Postlist\Services::instance()->clock() );
	}

	public function test_boot_is_idempotent(): void {
		$plugin = \SW\Postlist\Plugin::instance();
		$plugin->boot();
		$plugin->boot();
		$this->assertSame( 1, has_action( 'init', array( $plugin, 'load_textdomain' ) ) );
	}

	public function test_uninstall_file_runs_the_cleanup(): void {
		update_option( 'sw_postlist_version', '1.0.0' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'sw-postlist/sw-postlist.php' );
		}

		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'sw_postlist_version' ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_uninstall_cleans_every_site_of_a_network(): void {
		$this->skipWithoutMultisite();
		$second_site = self::factory()->blog->create();
		update_option( 'sw_postlist_version', '1.0.0' );
		switch_to_blog( $second_site );
		update_option( 'sw_postlist_version', '1.0.0' );
		update_option( 'other_plugin_option', 'keep' );
		restore_current_blog();

		Lifecycle::uninstall();

		$this->assertFalse( get_option( 'sw_postlist_version' ) );
		switch_to_blog( $second_site );
		$this->assertFalse( get_option( 'sw_postlist_version' ) );
		$this->assertSame( 'keep', get_option( 'other_plugin_option' ) );
		restore_current_blog();
	}
}
