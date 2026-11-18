<?php
/**
 * Current time for relative dates.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

interface ClockInterface {

	public function now(): \DateTimeImmutable;
}
