<?php
/**
 * Clock double.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support\Engine;

use SW\Postlist\TemplateEngine\Port\ClockInterface;

final class FixedClock implements ClockInterface {

	public function __construct( private readonly \DateTimeImmutable $moment ) {
	}

	public function now(): \DateTimeImmutable {
		return $this->moment;
	}
}
