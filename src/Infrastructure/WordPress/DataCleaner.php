<?php
/**
 * Removes every piece of data owned by the plugin, and only that.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

final class DataCleaner {

	/** Internal post types owned by the plugin (see docs/decisions.md ADR-02). */
	public const POST_TYPES = array( 'swp_view', 'swp_template' );

	public const OPTION_PREFIX = 'sw_postlist_';

	public function clean(): void {
		global $wpdb;

		foreach ( self::POST_TYPES as $post_type ) {
			$post_ids = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'any',
					'numberposts'            => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $post_ids as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
		}

		// Options and transients are matched by the exact plugin prefix, escaped for LIKE.
		$like_option    = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		$like_transient = $wpdb->esc_like( '_transient_' . self::OPTION_PREFIX ) . '%';
		$like_timeout   = $wpdb->esc_like( '_transient_timeout_' . self::OPTION_PREFIX ) . '%';
		$option_names   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like_option,
				$like_transient,
				$like_timeout
			)
		);
		foreach ( $option_names as $option_name ) {
			delete_option( (string) $option_name );
		}
	}
}
