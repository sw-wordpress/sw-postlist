<?php
/**
 * An HTML element with attributes and children.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Node;

final class ElementNode implements Node {

	/**
	 * @param string              $tag        Lowercase element name.
	 * @param list<AttributeNode> $attributes Attributes in source order.
	 * @param list<Node>          $children   Content.
	 * @param int                 $offset     Byte offset in the source.
	 */
	public function __construct(
		public readonly string $tag,
		public readonly array $attributes,
		public readonly array $children,
		public readonly int $offset
	) {
	}
}
