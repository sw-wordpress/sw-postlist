<?php
/**
 * Capability used for managing views and templates. Filterable for installations with custom roles.
 *
 * The internal post types keep WordPress' default, type specific primitive capabilities (for example
 * `edit_swp_templates`). Mapping them all to one shared capability would be wrong: WordPress remembers which meta
 * capability a primitive belongs to and would then turn that shared capability into a meta capability site-wide.
 * Instead, everyone who has the management capability implicitly holds the type specific ones.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

final class Capabilities {

	public const MANAGE = 'manage_options';

	public static function manage_capability(): string {
		/**
		 * Filters the capability that allows managing Postlist views and templates.
		 *
		 * @since 0.1.0
		 *
		 * @param string $capability Capability name, `manage_options` by default.
		 */
		return (string) apply_filters( 'sw_postlist_manage_capability', self::MANAGE );
	}

	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'grant_post_type_caps' ), 10, 1 );
	}

	/**
	 * @param array<string, bool> $all_caps Capabilities of the user.
	 * @return array<string, bool>
	 */
	public function grant_post_type_caps( array $all_caps ): array {
		if ( empty( $all_caps[ self::manage_capability() ] ) ) {
			return $all_caps;
		}
		foreach ( array( PostTypes::VIEW, PostTypes::TEMPLATE ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( null === $object ) {
				continue;
			}
			foreach ( get_object_vars( $object->cap ) as $capability ) {
				if ( is_string( $capability ) && 'read' !== $capability ) {
					$all_caps[ $capability ] = true;
				}
			}
		}
		return $all_caps;
	}
}
