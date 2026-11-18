<?php
/**
 * What the renderer needs to know about one embedding of a view.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Source\VisitorFilters;
use SW\Postlist\Domain\Template\TemplateDocument;

final class RenderRequest {

	/**
	 * @param string                $instance_key Stable key of this embedding ("news-1").
	 * @param int                   $page         Requested page, 1-based.
	 * @param VisitorFilters|null   $visitor      Visitor filters (M7).
	 * @param ContextOrigin|null    $origin       Origin of context dependent views (verified by the caller).
	 * @param array<string, mixed>  $overrides    Embedding overrides (validated against the view schema).
	 * @param bool                  $allow_draft  Whether a draft view may be rendered (admin preview).
	 * @param bool                  $inline_css   Whether template CSS is emitted inline once per page (otherwise returned).
	 * @param array<string, mixed>  $query_params Current GET parameters, kept in generated pagination links.
	 * @param TemplateDocument|null $container_template Unsaved container template (editor preview) replacing the stored one.
	 * @param TemplateDocument|null $entry_template     Unsaved entry template (editor preview) replacing the stored one.
	 */
	public function __construct(
		public readonly string $instance_key,
		public readonly int $page = 1,
		public readonly ?VisitorFilters $visitor = null,
		public readonly ?ContextOrigin $origin = null,
		public readonly array $overrides = array(),
		public readonly bool $allow_draft = false,
		public readonly bool $inline_css = true,
		public readonly array $query_params = array(),
		public readonly ?TemplateDocument $container_template = null,
		public readonly ?TemplateDocument $entry_template = null
	) {
	}
}
