<?php
/**
 * Builds the source registry: one source per public post type plus whatever the filter adds.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Query\SourceRegistry;

final class SourceRegistryFactory {

	public function create(): SourceRegistry {
		$sources = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			$sources[] = new PostTypeEntrySource( $post_type );
		}
		/**
		 * Registers entry sources (objects implementing EntrySourceInterface).
		 *
		 * Faulty registrations are reported in the diagnostics instead of causing an error.
		 *
		 * @since 0.1.0
		 *
		 * @param list<\SW\Postlist\Application\Port\EntrySourceInterface> $sources Registered sources.
		 */
		return new SourceRegistry( apply_filters( 'sw_postlist_entry_sources', $sources ) );
	}
}
