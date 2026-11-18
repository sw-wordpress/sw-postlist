<?php
/**
 * Registers the internal, non-public post types that store view and template documents (ADR-02).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

final class PostTypes {

	public const VIEW     = 'swp_view';
	public const TEMPLATE = 'swp_template';

	/** Post meta holding the schema-versioned JSON document (revisioned). */
	public const META_DOCUMENT = '_swp_document';

	/** Post meta of a view: one row per referenced template ID (queryable "where used"). */
	public const META_USES_TEMPLATE = '_swp_uses_template';

	public function register(): void {
		foreach ( array( self::VIEW, self::TEMPLATE ) as $post_type ) {
			register_post_type(
				$post_type,
				array(
					'labels'              => array(
						'name'          => self::VIEW === $post_type ? __( 'Postlist views', 'sw-postlist' ) : __( 'Postlist templates', 'sw-postlist' ),
						'singular_name' => self::VIEW === $post_type ? __( 'Postlist view', 'sw-postlist' ) : __( 'Postlist template', 'sw-postlist' ),
					),
					'public'              => false,
					'publicly_queryable'  => false,
					'exclude_from_search' => true,
					'show_ui'             => false,
					'show_in_menu'        => false,
					'show_in_nav_menus'   => false,
					'show_in_admin_bar'   => false,
					'show_in_rest'        => false,
					'has_archive'         => false,
					'rewrite'             => false,
					'query_var'           => false,
					'can_export'          => false,
					'delete_with_user'    => false,
					'hierarchical'        => false,
					'supports'            => array( 'title', 'revisions', 'custom-fields' ),
					'capability_type'     => array( $post_type, $post_type . 's' ),
					'map_meta_cap'        => true,
				)
			);
			register_post_meta(
				$post_type,
				self::META_DOCUMENT,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'revisions_enabled' => true,
					'auth_callback'     => '__return_false',
				)
			);
		}
	}
}
