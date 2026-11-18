<?php
/**
 * Fixed clock for deterministic tests and fixtures.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Support;

use SW\Postlist\Application\Port\ClockInterface;

final class FrozenClock implements ClockInterface {

	public function __construct( private \DateTimeImmutable $moment ) {
	}

	public function now(): \DateTimeImmutable {
		return $this->moment;
	}
}
