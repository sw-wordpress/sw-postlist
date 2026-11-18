<?php
/**
 * Where entries come from and how they are selected (PLAN.md §4). Implementations are registered through the
 * `sw_postlist_entry_sources` filter and validated by the registry.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\EntryResult;
use SW\Postlist\Domain\Source\QueryArgs;

interface EntrySourceInterface {

	/**
	 * Unique ID such as "post:event".
	 */
	public function id(): string;

	public function label(): string;

	/**
	 * Supported selection fields:
	 * `taxonomies` list<string>, `orderby` list<string>, `visitor_filters` list<string> (terms|author|search|orderby),
	 * `count_mode` "exact" or "continuation".
	 *
	 * @return array<string, mixed>
	 */
	public function query_fields(): array;

	/**
	 * One page of visible entries. Visibility, fixed selection and visitor filters are applied before counting.
	 */
	public function query( QueryArgs $args ): EntryResult;

	/**
	 * Placeholders contributed by this source (schema is validated in M2).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function placeholders(): array;

	/**
	 * Last check of a single entry; never widens the query result.
	 */
	public function can_view( Entry $entry, ?int $user_id ): bool;
}
