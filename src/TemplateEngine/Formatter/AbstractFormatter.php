<?php
/**
 * Base class of the built-in formatters: name and signature are given to the constructor.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Formatter;

use SW\Postlist\TemplateEngine\FormatterSignature;
use SW\Postlist\TemplateEngine\Port\FormatterInterface;

abstract class AbstractFormatter implements FormatterInterface {

	public function __construct( private readonly string $formatter_name, private readonly FormatterSignature $formatter_signature ) {
	}

	public function name(): string {
		return $this->formatter_name;
	}

	public function signature(): FormatterSignature {
		return $this->formatter_signature;
	}
}
