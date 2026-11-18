<?php
/**
 * A visitor filter type: its option schema and, for third-party types, how input is read and how the field is shown.
 *
 * Built-in types (`taxonomy`, `author`, `search`, `sort`) are read by {@see VisitorInputParser} and drawn by the
 * renderer. A third-party type supplies both callbacks:
 *
 * - `parse_callback( array $config, array $values ): array` receives the filter configuration and the visitor's raw
 *   values (list of strings) and returns any of `terms` (taxonomy => term IDs), `authors`, `search`, `orderby`, `order`.
 *   The result is sanitized again; filters can only narrow the selection.
 * - `render_callback( array $config, array $values, string $name, string $id ): string` returns the HTML of the field
 *   (the callback escapes). `$name` is the form field name to use, `$id` a unique element ID.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Filter;

final class FilterTypeDefinition {

	/**
	 * @param string                              $id              Filter type ID ([a-z0-9_-]{1,40}).
	 * @param string                              $label           Display name.
	 * @param array<string, array<string, mixed>> $schema          Option schema (without the always allowed `type`).
	 * @param mixed                               $parse_callback  See class description; checked by the registry.
	 * @param mixed                               $render_callback See class description; checked by the registry.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly array $schema = array(),
		public readonly mixed $parse_callback = null,
		public readonly mixed $render_callback = null
	) {
	}
}
