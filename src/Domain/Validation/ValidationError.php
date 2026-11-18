<?php
/**
 * One finding of a document or input validation.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Validation;

final class ValidationError {

	public function __construct(
		public readonly string $code,
		public readonly string $path,
		public readonly string $message
	) {
	}

	/**
	 * @return array{code: string, path: string, message: string}
	 */
	public function to_array(): array {
		return array(
			'code'    => $this->code,
			'path'    => $this->path,
			'message' => $this->message,
		);
	}
}
