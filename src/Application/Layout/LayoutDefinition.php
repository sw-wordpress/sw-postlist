<?php
/**
 * A layout type: its option schema, the optional frontend module and an optional render callback for third parties.
 *
 * Option schema: `option => array( 'type' => 'int|bool|string|enum|device_int|device_bool', 'min' => int, 'max' => int,
 * 'values' => list<string>, 'pattern' => string )`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Layout;

final class LayoutDefinition {

	/**
	 * @param string                              $id              Layout ID ([a-z0-9_-]{1,40}).
	 * @param string                              $label           Display name.
	 * @param array<string, array<string, mixed>> $schema          Option schema (without the always allowed `type`).
	 * @param string|null                         $module          Frontend module handle (assets/js/{module}.js) or null.
	 * @param mixed                               $render_callback Callable `(string $items_html, array $options): string` that wraps the rendered entries, or null; checked by the registry.
	 * @param string                              $item_tag        Element wrapping each entry ("div" or "li").
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly array $schema = array(),
		public readonly ?string $module = null,
		public readonly mixed $render_callback = null,
		public readonly string $item_tag = 'div'
	) {
	}
}
