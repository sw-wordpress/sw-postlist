<?php
/**
 * Attribute of a start tag as read by the lexer; the value is a list of text and placeholder tokens.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Syntax;

final class RawAttribute {

	/**
	 * @param string           $name   Lowercase attribute name.
	 * @param list<Token>|null $value  Text and mustache tokens, null for an attribute without value.
	 * @param int              $offset Byte offset of the attribute name.
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?array $value,
		public readonly int $offset
	) {
	}
}
