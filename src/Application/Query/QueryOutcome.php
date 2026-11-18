<?php
/**
 * Result of a query run: a page of entries or a neutral error without any count.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Query;

use SW\Postlist\Domain\Source\EntryResult;

final class QueryOutcome {

	private function __construct(
		public readonly ?EntryResult $result,
		public readonly ?string $error_code
	) {
	}

	public static function success( EntryResult $result ): self {
		return new self( $result, null );
	}

	public static function failure( string $error_code ): self {
		return new self( null, $error_code );
	}

	public function is_success(): bool {
		return null !== $this->result;
	}
}
