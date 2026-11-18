<?php
/**
 * Markup produced by the host that is output without escaping (HTML components such as an image tag).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class TrustedHtml {

	public function __construct( public readonly string $html ) {
	}
}
