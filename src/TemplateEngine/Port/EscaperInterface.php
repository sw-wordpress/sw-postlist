<?php
/**
 * Output escaping and URL checks. The engine itself calls no WordPress function; the host supplies this adapter.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

interface EscaperInterface {

	/** Escapes text for an HTML text node. */
	public function text( string $value ): string;

	/** Escapes a value for a quoted HTML attribute. */
	public function attribute( string $value ): string;

	/** Normalized, safe URL (http, https, mailto, tel, relative) or null when the value must not be output. */
	public function url( string $value ): ?string;

	/** `#rrggbb` (lowercase) or null. */
	public function color( string $value ): ?string;
}
