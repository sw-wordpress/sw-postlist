<?php
/**
 * Schema entry of one placeholder: name, type, description, example and the template roles that may use it.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class PlaceholderDefinition {

	/**
	 * @param string       $name        Dotted name such as "entry.title".
	 * @param ValueType    $type        Data type of the resolved value.
	 * @param string       $description Human readable description for the editor panel.
	 * @param string       $example     Example value for the live preview of the panel.
	 * @param list<string> $roles       Template roles that may use the placeholder; empty means every role.
	 */
	public function __construct(
		public readonly string $name,
		public readonly ValueType $type,
		public readonly string $description = '',
		public readonly string $example = '',
		public readonly array $roles = array()
	) {
	}

	public function allows_role( string $role ): bool {
		return array() === $this->roles || in_array( $role, $this->roles, true );
	}

	/**
	 * @return array{name: string, type: string, description: string, example: string, roles: list<string>}
	 */
	public function to_array(): array {
		return array(
			'name'        => $this->name,
			'type'        => $this->type->value,
			'description' => $this->description,
			'example'     => $this->example,
			'roles'       => $this->roles,
		);
	}
}
