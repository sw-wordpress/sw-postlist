<?php
/**
 * Clock backed by the system time.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Support;

use SW\Postlist\Application\Port\ClockInterface;

final class SystemClock implements ClockInterface {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
