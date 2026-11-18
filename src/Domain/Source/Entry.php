<?php
/**
 * One entry of a list: identity plus presentation data. Identity and membership are checked by the query service.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Source;

final class Entry {

	/**
	 * @param int|string           $id        Identity within the source.
	 * @param string               $source_id Entry source ID.
	 * @param array<string, mixed> $data      Presentation data (extensions may add to it, never change identity).
	 * @param mixed                $native    Source-specific handle (for example the WP_Post) for value resolvers.
	 */
	public function __construct(
		public readonly int|string $id,
		public readonly string $source_id,
		public readonly array $data = array(),
		public readonly mixed $native = null
	) {
	}

	/**
	 * @param array<string, mixed> $data Replacement presentation data.
	 */
	public function with_data( array $data ): self {
		return new self( $this->id, $this->source_id, $data, $this->native );
	}
}
