<?php
/**
 * Fixed selection criteria of a view (the editor-defined part of the query).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\View;

final class QueryCriteria {

	public const CONTEXT_MODES = array( 'none', 'same_category', 'same_tags', 'same_author', 'archive', 'search' );
	public const ORDERBY       = array( 'date', 'modified', 'title', 'menu_order', 'id', 'meta_num', 'meta_text' );
	public const OPERATORS     = array( 'in', 'not_in', 'and' );

	/**
	 * @param list<array{taxonomy: string, terms: list<int>, operator: string}> $taxonomies Term filters.
	 * @param list<int>                                                         $authors    Author IDs.
	 * @param list<array{key: string, value: string, compare: string}>          $meta       Meta filters.
	 * @param list<int>                                                         $include    Only these IDs.
	 * @param list<int>                                                         $exclude    Never these IDs.
	 */
	public function __construct(
		public readonly array $taxonomies = array(),
		public readonly array $authors = array(),
		public readonly ?string $date_after = null,
		public readonly ?string $date_before = null,
		public readonly array $meta = array(),
		public readonly array $include = array(),
		public readonly array $exclude = array(),
		public readonly int $per_page = 10,
		public readonly int $offset = 0,
		public readonly string $orderby = 'date',
		public readonly string $order = 'desc',
		public readonly ?string $order_meta_key = null,
		public readonly string $context = 'none'
	) {
	}
}
