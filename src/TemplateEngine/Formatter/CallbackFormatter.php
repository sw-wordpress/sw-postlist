<?php
/**
 * A formatter defined by a name, a signature and a callback. Used for the built-in set and by extensions that do not
 * need their own class.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Formatter;

use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\FormatterSignature;

final class CallbackFormatter extends AbstractFormatter {

	/**
	 * @param \Closure(mixed, list<string|int|bool>, FormatContext): mixed $callback Implementation.
	 */
	public function __construct( string $name, FormatterSignature $signature, private readonly \Closure $callback ) {
		parent::__construct( $name, $signature );
	}

	public function apply( mixed $value, array $params, FormatContext $context ): mixed {
		return ( $this->callback )( $value, $params, $context );
	}
}
