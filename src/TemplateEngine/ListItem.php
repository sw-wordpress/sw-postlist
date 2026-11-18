<?php
/**
 * Element of a list value (terms, tags …): a label with an optional URL.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class ListItem {

	public function __construct(
		public readonly string $label,
		public readonly ?string $url = null,
		public readonly ?string $key = null
	) {
	}
}
