<?php
/**
 * Everything the compiler needs besides the template: profile, placeholder schema, formatters and the template role.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Port\FormatterRegistryInterface;
use SW\Postlist\TemplateEngine\Port\PlaceholderSchemaInterface;

final class CompileContext {

	public function __construct(
		public readonly EngineProfile $profile,
		public readonly PlaceholderSchemaInterface $schema,
		public readonly FormatterRegistryInterface $formatters,
		public readonly string $role
	) {
	}
}
