<?php
/**
 * Everything a source needs for one page request.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Source;

use SW\Postlist\Domain\View\QueryCriteria;

final class QueryArgs {

	public function __construct(
		public readonly string $source_id,
		public readonly QueryCriteria $criteria,
		public readonly int $page = 1,
		public readonly ?VisitorFilters $visitor = null,
		public readonly ?ContextOrigin $origin = null,
		public readonly ?int $view_id = null,
		public readonly ?int $user_id = null
	) {
		if ( $page < 1 ) {
			throw new \InvalidArgumentException( 'The page number starts at 1.' );
		}
	}

	/** Offset of the first entry of the requested page in the visible result set (start offset included). */
	public function query_offset(): int {
		return $this->criteria->offset + ( $this->page - 1 ) * $this->criteria->per_page;
	}
}
