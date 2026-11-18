<?php
/**
 * Meta keys a template may read, per entry source. Nothing outside the list is ever exposed through `entry.meta.*`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

final class MetaAllowlist {

	public const SETTINGS_OPTION = 'sw_postlist_settings';

	/**
	 * @return list<string> Allowed keys, sorted, protected (`_`-prefixed) and malformed keys removed.
	 */
	public function keys( string $source_id ): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$keys     = array();
		if ( is_array( $settings ) && is_array( $settings['meta_keys'] ?? null ) && is_array( $settings['meta_keys'][ $source_id ] ?? null ) ) {
			$keys = self::sanitize( $settings['meta_keys'][ $source_id ] );
		}
		/**
		 * Filters the meta keys that templates of an entry source may read.
		 *
		 * @since 0.1.0
		 *
		 * @param list<string> $keys      Keys allowed in the settings.
		 * @param string       $source_id Entry source ID.
		 */
		$filtered = apply_filters( 'sw_postlist_allowed_meta_keys', $keys, $source_id );
		return self::sanitize( $filtered );
	}

	/**
	 * @return list<string>
	 */
	public static function sanitize( mixed $keys ): array {
		$clean = array();
		foreach ( is_array( $keys ) ? $keys : array() as $key ) {
			if ( is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $key ) ) {
				$clean[ $key ] = $key;
			}
		}
		$clean = array_values( $clean );
		sort( $clean );
		return $clean;
	}
}
