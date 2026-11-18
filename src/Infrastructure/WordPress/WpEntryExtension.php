<?php
/**
 * `sw_postlist_entries`: lets extensions enrich entries. The result is verified by the query service.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Port\EntryExtensionInterface;
use SW\Postlist\Domain\Source\QueryArgs;

final class WpEntryExtension implements EntryExtensionInterface {

	public function filter_entries( array $entries, QueryArgs $args ): mixed {
		/**
		 * Enrich the presentation data of the entries of a page.
		 *
		 * Entries must keep identity, membership and order; hide entries in the source query instead
		 * (sw_postlist_query_args). A violation produces a neutral error without counts.
		 *
		 * @since 0.1.0
		 *
		 * @param list<\SW\Postlist\Domain\Source\Entry> $entries Entries of the page.
		 * @param QueryArgs                              $args    Request that produced the page.
		 */
		return apply_filters( 'sw_postlist_entries', $entries, $args );
	}
}
