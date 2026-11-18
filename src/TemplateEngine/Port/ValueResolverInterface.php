<?php
/**
 * Resolves placeholder values for the entry or container being rendered.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

interface ValueResolverInterface {

	/**
	 * Raw typed value: string (text, url, color), int|float (number), DateTimeImmutable (date), list<ListItem>
	 * (list), TrustedHtml (html), bool (flag), host specific image value (image) or null when empty.
	 */
	public function resolve( string $name ): mixed;
}
