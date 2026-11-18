<?php
/**
 * A syntax, allowlist or type finding of the compiler, with source position.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class TemplateError {

	/**
	 * @param string               $code    Stable machine readable code.
	 * @param string               $message Plain text message.
	 * @param int                  $line    1-based line.
	 * @param int                  $column  1-based column.
	 * @param array<string, mixed> $context Additional machine readable details (tag, name, formatter …).
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly int $line = 1,
		public readonly int $column = 1,
		public readonly array $context = array()
	) {
	}

	/**
	 * @return array{code: string, message: string, line: int, column: int, context: array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'code'    => $this->code,
			'message' => $this->message,
			'line'    => $this->line,
			'column'  => $this->column,
			'context' => $this->context,
		);
	}
}
