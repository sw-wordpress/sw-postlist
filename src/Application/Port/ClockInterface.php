<?php
/**
 * Time source port.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

interface ClockInterface {

	/**
	 * Current point in time (UTC).
	 */
	public function now(): \DateTimeImmutable;
}
