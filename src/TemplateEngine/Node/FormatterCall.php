<?php
/**
 * One formatter in a chain with its literal parameters.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Node;

final class FormatterCall {

	/**
	 * @param string                $name   Formatter name.
	 * @param list<string|int|bool> $params Literal parameters.
	 * @param int                   $offset Byte offset in the source.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $params,
		public readonly int $offset
	) {
	}
}
