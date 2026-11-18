<?php
/**
 * Visitor chosen filters. They form an intersection with the fixed criteria of the view, never a widening.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Source;

final class VisitorFilters {

	/**
	 * @param array<string, list<int>> $terms   Selected term IDs by taxonomy.
	 * @param list<int>                $authors Selected author IDs.
	 * @param string|null              $search  Search text.
	 * @param string|null              $orderby Requested sort field (must be offered by the source).
	 * @param string|null              $order   asc|desc.
	 */
	public function __construct(
		public readonly array $terms = array(),
		public readonly array $authors = array(),
		public readonly ?string $search = null,
		public readonly ?string $orderby = null,
		public readonly ?string $order = null
	) {
	}

	public function is_empty(): bool {
		return array() === $this->terms && array() === $this->authors && null === $this->search && null === $this->orderby;
	}
}
