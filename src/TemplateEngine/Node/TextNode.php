<?php
/**
 * Static text. Normalized (entities decoded and escaped again) when the AST is built.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Node;

final class TextNode implements Node {

	public function __construct( public readonly string $text, public readonly int $offset = 0 ) {
	}
}
