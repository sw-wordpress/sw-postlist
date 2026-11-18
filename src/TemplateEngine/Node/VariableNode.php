<?php
/**
 * `{{name}}` or `{{name|formatter:param}}`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Node;

final class VariableNode implements Node {

	/**
	 * @param string              $name   Placeholder name.
	 * @param list<FormatterCall> $chain  Formatter chain.
	 * @param int                 $offset Byte offset in the source.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $chain,
		public readonly int $offset
	) {
	}
}
