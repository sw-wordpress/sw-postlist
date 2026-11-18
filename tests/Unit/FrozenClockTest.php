<?php
/**
 * Tests for the deterministic clock.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SW\Postlist\Support\FrozenClock;
use SW\Postlist\Support\SystemClock;

final class FrozenClockTest extends TestCase {

	public function test_frozen_clock_returns_fixed_moment(): void {
		$moment = new \DateTimeImmutable( '2026-11-18T10:00:00Z' );
		$clock  = new FrozenClock( $moment );

		$this->assertSame( $moment, $clock->now() );
	}

	public function test_system_clock_is_utc(): void {
		$this->assertSame( 'UTC', ( new SystemClock() )->now()->getTimezone()->getName() );
	}
}
