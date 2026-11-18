<?php
/**
 * WordPress admin integration: the "Postlist" menu hosting the Vue/Quasar single page application.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Admin;

use SW\Postlist\Infrastructure\WordPress\Capabilities;

final class AdminPage {

	public const SLUG    = 'sw-postlist';
	private const HANDLE = 'sw-postlist-admin';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );
	}

	public function add_menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Postlist', 'sw-postlist' ),
			__( 'Postlist', 'sw-postlist' ),
			Capabilities::manage_capability(),
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-list-view',
			27
		);
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-postlist' ), 403 );
		}
		if ( ! is_readable( SW_POSTLIST_DIR . 'assets/admin/app.js' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'The Postlist administration has not been built. Install the release package or run "npm run build" in apps/admin.', 'sw-postlist' ) . '</p></div></div>';
			return;
		}
		echo '<div id="sw-postlist-app"></div><noscript>' . esc_html__( 'The Postlist administration requires JavaScript.', 'sw-postlist' ) . '</noscript>';
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook || ! is_readable( SW_POSTLIST_DIR . 'assets/admin/app.js' ) ) {
			return;
		}
		// The plugin version busts browser caches after updates; lazy chunks carry a content hash in their file name.
		wp_enqueue_style( self::HANDLE, SW_POSTLIST_URL . 'assets/admin/app.css', array(), SW_POSTLIST_VERSION );
		wp_enqueue_script( self::HANDLE, SW_POSTLIST_URL . 'assets/admin/app.js', array(), SW_POSTLIST_VERSION, true );
		wp_add_inline_script( self::HANDLE, 'window.swpostlistConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Configuration contract with the SPA (apps/admin/src/types/config.ts).
	 *
	 * @return array<string, string>
	 */
	public function config(): array {
		return array(
			'restRoot'  => esc_url_raw( rest_url( 'sw-postlist/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'locale'    => determine_locale(),
			'version'   => SW_POSTLIST_VERSION,
			'adminUrl'  => esc_url_raw( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'siteUrl'   => esc_url_raw( site_url() ),
			'pluginUrl' => esc_url_raw( SW_POSTLIST_URL ),
		);
	}

	public function module_tag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}
}
