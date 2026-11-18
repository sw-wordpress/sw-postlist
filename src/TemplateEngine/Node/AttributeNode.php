<?php
/**
 * Attribute of an element; the value consists of text, variable and section nodes.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Node;

final class AttributeNode {

	/**
	 * @param string          $name   Lowercase attribute name.
	 * @param list<Node>|null $value  Value nodes, null for an attribute without value.
	 * @param int             $offset Byte offset in the source.
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?array $value,
		public readonly int $offset
	) {
	}
}
