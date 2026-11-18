<?php
/**
 * Reads the origin of context dependent views from the WordPress main query, before any Postlist query runs.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Domain\Source\ContextOrigin;

final class MainQueryOriginReader {

	public function read( ?\WP_Query $main_query = null ): ?ContextOrigin {
		$main_query ??= $GLOBALS['wp_query'] ?? null;
		if ( ! $main_query instanceof \WP_Query ) {
			return null;
		}
		if ( $main_query->is_search() ) {
			$text = trim( (string) $main_query->get( 's' ) );
			return '' === $text ? null : ContextOrigin::search( $text );
		}
		if ( $main_query->is_author() ) {
			$author = (int) $main_query->get( 'author' );
			return $author > 0 ? ContextOrigin::author( $author ) : null;
		}
		if ( $main_query->is_category() || $main_query->is_tag() || $main_query->is_tax() ) {
			$object = $main_query->get_queried_object();
			return $object instanceof \WP_Term ? ContextOrigin::term( $object->taxonomy, $object->term_id ) : null;
		}
		if ( $main_query->is_singular() ) {
			$post_id = (int) $main_query->get_queried_object_id();
			return $post_id > 0 ? ContextOrigin::post( $post_id ) : null;
		}
		return null;
	}
}
