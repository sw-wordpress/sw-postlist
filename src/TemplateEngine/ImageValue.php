<?php
/**
 * An image resolved by the host: default URL, alternative text and the host's attachment ID for size specific output.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class ImageValue {

	public function __construct(
		public readonly string $url,
		public readonly string $alt = '',
		public readonly int $attachment_id = 0
	) {
	}
}
