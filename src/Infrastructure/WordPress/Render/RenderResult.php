<?php
/**
 * Outcome of rendering a view: markup and counts, or an error code that is never shown to visitors.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

final class RenderResult {

	/**
	 * @param string      $html        Markup (without the error details).
	 * @param string      $css         Scoped template CSS (also when inlined into the markup).
	 * @param int|null    $total       Exact visible total after the start offset, null for continuation sources.
	 * @param bool        $has_more    Whether another visible entry follows this page.
	 * @param int         $page        Rendered page.
	 * @param int         $count       Number of rendered entries.
	 * @param string|null $error_code  Machine readable error, null on success.
	 */
	public function __construct(
		public readonly string $html,
		public readonly string $css = '',
		public readonly ?int $total = null,
		public readonly bool $has_more = false,
		public readonly int $page = 1,
		public readonly int $count = 0,
		public readonly ?string $error_code = null
	) {
	}

	public static function error( string $error_code, string $html = '' ): self {
		return new self( $html, '', null, false, 1, 0, $error_code );
	}

	public function is_success(): bool {
		return null === $this->error_code;
	}
}
