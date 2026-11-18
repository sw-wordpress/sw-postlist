<?php
/**
 * A repository operation failed for a reason the caller can act on.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

final class DocumentRepositoryException extends \RuntimeException {

	/**
	 * @param string               $error_code Stable machine readable code.
	 * @param string               $message    Plain text message.
	 * @param array<string, mixed> $details    Non-sensitive details.
	 */
	public function __construct( public readonly string $error_code, string $message, public readonly array $details = array() ) {
		parent::__construct( $message );
	}
}
