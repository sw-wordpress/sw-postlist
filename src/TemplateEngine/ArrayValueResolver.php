<?php
/**
 * Resolver for values the host computed up front (container placeholders such as `view.title`).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Port\ValueResolverInterface;

final class ArrayValueResolver implements ValueResolverInterface {

	/**
	 * @param array<string, mixed> $values Typed values by placeholder name.
	 */
	public function __construct( private readonly array $values ) {
	}

	public function resolve( string $name ): mixed {
		return $this->values[ $name ] ?? null;
	}
}
