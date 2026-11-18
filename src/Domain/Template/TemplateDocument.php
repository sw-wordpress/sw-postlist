<?php
/**
 * Validated, schema-versioned template document. The template language itself is validated in M2.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Template;

final class TemplateDocument {

	public const SCHEMA_VERSION = 1;

	public function __construct(
		public readonly string $title,
		public readonly TemplateRole $role,
		public readonly string $html,
		public readonly string $css
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'title'          => $this->title,
			'role'           => $this->role->value,
			'html'           => $this->html,
			'css'            => $this->css,
		);
	}
}
