<?php
/**
 * Activation, deactivation and uninstall. Only SW Postlist data is ever touched.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist;

use SW\Postlist\Infrastructure\WordPress\DataCleaner;

final class Lifecycle {

	/**
	 * @param bool $network_wide Network activation flag passed by WordPress (state is per site).
	 */
	public static function activate( bool $network_wide = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature dictated by register_activation_hook().
		update_option( 'sw_postlist_version', SW_POSTLIST_VERSION, false );
	}

	/** Deactivation keeps all data. */
	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}

	/** Removes plugin options, posts of the internal types and transients; nothing else. */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					( new DataCleaner() )->clean();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}
		( new DataCleaner() )->clean();
	}
}
