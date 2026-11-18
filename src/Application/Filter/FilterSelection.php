<?php
/**
 * What a visitor selected: the filters for the query and the normalized values per parameter (for drawing the form).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Filter;

use SW\Postlist\Domain\Source\VisitorFilters;

final class FilterSelection {

	/**
	 * @param array<string, list<string>> $params   Normalized values by URL parameter name.
	 * @param list<string>                $problems IDs of third-party filter types whose callback failed.
	 */
	public function __construct(
		public readonly VisitorFilters $visitor,
		public readonly array $params = array(),
		public readonly array $problems = array()
	) {
	}

	/**
	 * @return list<string>
	 */
	public function values( string $param ): array {
		return $this->params[ $param ] ?? array();
	}
}
