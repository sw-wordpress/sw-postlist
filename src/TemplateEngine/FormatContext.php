<?php
/**
 * Services available to formatters.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Port\ClockInterface;
use SW\Postlist\TemplateEngine\Port\EscaperInterface;
use SW\Postlist\TemplateEngine\Port\LocaleInterface;

final class FormatContext {

	public function __construct(
		public readonly LocaleInterface $locale,
		public readonly ClockInterface $clock,
		public readonly EscaperInterface $escaper
	) {
	}
}
