<?php
/**
 * Lexer token: text, placeholder tag, element start or element end.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Syntax;

final class Token {

	public const TEXT      = 'text';
	public const MUSTACHE  = 'mustache';
	public const TAG_OPEN  = 'tag_open';
	public const TAG_CLOSE = 'tag_close';

	/**
	 * @param string             $kind         One of the kind constants.
	 * @param int                $offset       Byte offset in the normalized source.
	 * @param string             $value        Text, element name or the raw placeholder expression.
	 * @param string             $sigil        Mustache sigil: "", "#", "^" or "/".
	 * @param list<RawAttribute> $attributes   Attributes of a start tag.
	 * @param bool               $self_closing Whether a start tag ended with "/>".
	 */
	public function __construct(
		public readonly string $kind,
		public readonly int $offset,
		public readonly string $value = '',
		public readonly string $sigil = '',
		public readonly array $attributes = array(),
		public readonly bool $self_closing = false
	) {
	}
}
