<?php
/**
 * `{{#name}}…{{/name}}` or inverted `{{^name}}…{{/name}}`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Node;

final class SectionNode implements Node {

	/**
	 * @param string     $name     Placeholder name.
	 * @param bool       $inverted Whether this is an inverted section.
	 * @param list<Node> $children Content.
	 * @param int        $offset   Byte offset in the source.
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $inverted,
		public readonly array $children,
		public readonly int $offset
	) {
	}
}
