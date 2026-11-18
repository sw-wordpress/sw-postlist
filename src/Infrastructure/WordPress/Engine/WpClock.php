<?php
/**
 * Current time as seen by the engine (UTC instant; formatting applies the site time zone).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\TemplateEngine\Port\ClockInterface;

final class WpClock implements ClockInterface {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', wp_timezone() );
	}
}
