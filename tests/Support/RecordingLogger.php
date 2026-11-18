<?php
/**
 * Logger double that records warnings.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support;

use SW\Postlist\Application\Port\LoggerInterface;

final class RecordingLogger implements LoggerInterface {

	/** @var list<string> */
	public array $codes = array();

	public function warning( string $code, string $message, array $context = array() ): void {
		$this->codes[] = $code;
	}
}
