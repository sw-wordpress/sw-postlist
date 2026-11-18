<?php
/**
 * In-memory test double for the package service tests (no WordPress).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support;

use SW\Postlist\Domain\Preset\Preset;

/**
 * Preset store whose writes fail.
 */
final class FailingPresets extends MemoryPresets {

	public function save_user( Preset $preset ): void {
		throw new \RuntimeException( 'option write failed' );
	}
}
