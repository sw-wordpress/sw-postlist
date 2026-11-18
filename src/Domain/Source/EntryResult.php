<?php
/**
 * Page of entries of one source. `total` is the visible total after the start offset, or null when the source
 * only offers a verified continuation (PLAN.md §4, ADR-05).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Source;

final class EntryResult {

	/**
	 * @param list<Entry> $entries  Visible entries of the requested page, in display order.
	 * @param int|null    $total    Exact visible total or null.
	 * @param bool        $has_more Whether a further visible entry follows this page (always exact).
	 */
	public function __construct(
		public readonly array $entries,
		public readonly ?int $total,
		public readonly bool $has_more
	) {
	}

	public static function empty( ?int $total = 0 ): self {
		return new self( array(), $total, false );
	}

	/**
	 * @param list<Entry> $entries Replacement entries (same identity, order and membership).
	 */
	public function with_entries( array $entries ): self {
		return new self( $entries, $this->total, $this->has_more );
	}
}
