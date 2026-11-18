<?php
/**
 * Available formatters. An empty registry means formatters are switched off.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

interface FormatterRegistryInterface {

	public function get( string $name ): ?FormatterInterface;

	/**
	 * @return array<string, FormatterInterface>
	 */
	public function all(): array;

	/**
	 * Fingerprint of all formatter signatures (part of the AST cache key).
	 */
	public function fingerprint(): string;
}
