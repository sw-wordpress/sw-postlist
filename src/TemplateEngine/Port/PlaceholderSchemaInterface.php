<?php
/**
 * Placeholder catalogue supplied by the host plugin (`entry.*`, `view.*`, …).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

use SW\Postlist\TemplateEngine\PlaceholderDefinition;

interface PlaceholderSchemaInterface {

	/**
	 * Definition of a placeholder usable in templates of the given role, or null when unknown.
	 */
	public function find( string $name, string $role ): ?PlaceholderDefinition;

	/**
	 * All placeholders of a role, for the editor panel.
	 *
	 * @return list<PlaceholderDefinition>
	 */
	public function all( string $role ): array;

	/**
	 * Fingerprint of everything that influences compilation (names, types, allowed meta keys).
	 */
	public function fingerprint( string $role ): string;
}
