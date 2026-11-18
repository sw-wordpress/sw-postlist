<?php
/**
 * One whitelisted formatter. There is no general function call in templates.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\FormatterSignature;

interface FormatterInterface {

	public function name(): string;

	public function signature(): FormatterSignature;

	/**
	 * @param mixed                 $value   Input of one of the signature's input types.
	 * @param list<string|int|bool> $params  Validated literal parameters.
	 * @param FormatContext         $context Locale, clock and escaper.
	 * @return mixed Value of the signature's output type, null when empty.
	 */
	public function apply( mixed $value, array $params, FormatContext $context ): mixed;
}
