<?php
/**
 * Extension point that may enrich the presentation data of entries (`sw_postlist_entries`).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\QueryArgs;

interface EntryExtensionInterface {

	/**
	 * @param list<Entry> $entries Entries of the page.
	 * @return mixed Extensions are untrusted: the query service verifies the returned value.
	 */
	public function filter_entries( array $entries, QueryArgs $args ): mixed;
}
