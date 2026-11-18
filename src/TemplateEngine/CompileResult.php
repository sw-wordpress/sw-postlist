<?php
/**
 * Outcome of compiling a template: the compiled template or the list of errors.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class CompileResult {

	/**
	 * @param CompiledTemplate|null $template Compiled template, null when errors exist.
	 * @param list<TemplateError>   $errors   Findings, empty on success.
	 */
	public function __construct(
		public readonly ?CompiledTemplate $template,
		public readonly array $errors = array()
	) {
	}

	public function is_valid(): bool {
		return null !== $this->template && array() === $this->errors;
	}

	/**
	 * @return list<string>
	 */
	public function error_codes(): array {
		return array_map( static fn ( TemplateError $error ): string => $error->code, $this->errors );
	}
}
