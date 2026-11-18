<?php
/**
 * Diagnostic log port. Never receives secrets or visitor data.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

interface LoggerInterface {

	/**
	 * @param string               $code    Stable machine readable code.
	 * @param string               $message Human readable message.
	 * @param array<string, mixed> $context Non-sensitive details.
	 */
	public function warning( string $code, string $message, array $context = array() ): void;
}
