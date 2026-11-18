<?php
/**
 * Registration of the frontend stylesheet. Loaded only when a view is actually rendered; the version parameter is the
 * plugin version (cache busting after updates).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Frontend;

final class FrontendAssets {

	public const STYLE = 'sw-postlist-frontend';

	public static function register(): void {
		wp_register_style( self::STYLE, SW_POSTLIST_URL . 'assets/css/frontend.css', array(), SW_POSTLIST_VERSION );
	}

	/** Enqueues the stylesheet for the current request (idempotent). */
	public static function enqueue(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			self::register();
		}
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Loads the script of a layout module (assets/js/{module}.js) for the current request, and only then: pages that use
	 * no layout with a module ship no Postlist JavaScript at all.
	 */
	public static function enqueue_module( string $module ): void {
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $module ) || ! is_readable( SW_POSTLIST_DIR . 'assets/js/' . $module . '.js' ) ) {
			return;
		}
		$handle = 'sw-postlist-' . $module;
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				SW_POSTLIST_URL . 'assets/js/' . $module . '.js',
				array(),
				SW_POSTLIST_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		$config = 'window.SWPostlist=window.SWPostlist||{};window.SWPostlist.i18n=Object.assign(window.SWPostlist.i18n||{},' . wp_json_encode( self::strings(), JSON_HEX_TAG | JSON_HEX_AMP ) . ');';
		if ( 'dynamic' === $module ) {
			// The reload route is public and needs no nonce (docs/rest-api.md); the root is all the module needs.
			$config .= 'window.SWPostlist.rest=' . wp_json_encode( esc_url_raw( rest_url( 'sw-postlist/v1/' ) ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';';
		}
		wp_add_inline_script( $handle, $config, 'before' );
		wp_enqueue_script( $handle );
	}

	/**
	 * Labels of the controls that the layout modules create.
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			'previous'  => __( 'Previous slide', 'sw-postlist' ),
			'next'      => __( 'Next slide', 'sw-postlist' ),
			'pause'     => __( 'Pause autoplay', 'sw-postlist' ),
			'play'      => __( 'Start autoplay', 'sw-postlist' ),
			'loading'   => __( 'Loading…', 'sw-postlist' ),
			'error'     => __( 'The list could not be loaded.', 'sw-postlist' ),
			'retry'     => __( 'Try again', 'sw-postlist' ),
			'more'      => __( 'Load more', 'sw-postlist' ),
			/* translators: %d: number of entries shown (two or more) */
			'shown'     => __( '%d entries shown.', 'sw-postlist' ),
			'shown_one' => __( '1 entry shown.', 'sw-postlist' ),
			/* translators: %d: page number */
			'page'      => __( 'Page %d loaded.', 'sw-postlist' ),
			'empty'     => __( 'No entries found.', 'sw-postlist' ),
		);
	}
}
