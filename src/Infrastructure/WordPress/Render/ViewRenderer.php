<?php
/**
 * The one render function behind block, shortcode, REST preview and (later) the public REST route: query, templates,
 * layout and pagination for a stored view.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Filter\FilterTypeRegistry;
use SW\Postlist\Application\Filter\VisitorInputParser;
use SW\Postlist\Application\Layout\LayoutDefinition;
use SW\Postlist\Application\Layout\LayoutRegistry;
use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Application\Port\LoggerInterface;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Application\Query\QueryService;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Domain\View\ViewDocument;
use SW\Postlist\Infrastructure\WordPress\Capabilities;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateEngineFactory;
use SW\Postlist\Presentation\Frontend\FrontendAssets;
use SW\Postlist\TemplateEngine\ArrayValueResolver;
use SW\Postlist\TemplateEngine\CompiledTemplate;
use SW\Postlist\TemplateEngine\TrustedHtml;

final class ViewRenderer {

	/**
	 * Embedding overrides a shortcode or block may set, by section. They are merged into the stored document and
	 * validated again by the same schema.
	 */
	public const OVERRIDABLE = array(
		'layout'     => array( 'type', 'columns', 'gap', 'aspect_ratio', 'font_size', 'hidden', 'accent' ),
		'query'      => array( 'per_page', 'orderby', 'order', 'order_meta_key' ),
		'pagination' => array( 'mode' ),
	);

	/** @var array<string, true> */
	private static array $emitted_css = array();

	public function __construct(
		private readonly ViewRepositoryInterface $views,
		private readonly TemplateRepositoryInterface $templates,
		private readonly SourceRegistry $sources,
		private readonly QueryService $query,
		private readonly TemplateEngineFactory $engine,
		private readonly ViewDocumentValidator $validator,
		private readonly LayoutRegistry $layouts,
		private readonly FilterTypeRegistry $filter_types,
		private readonly LoggerInterface $logger,
		private readonly OriginSigner $signer = new OriginSigner(),
		private readonly PaginationRenderer $pagination = new PaginationRenderer()
	) {
	}

	/** Request boundary and test seam. */
	public static function reset(): void {
		self::$emitted_css = array();
	}

	public function render( int $view_id, RenderRequest $request ): RenderResult {
		/**
		 * Fires before a view is rendered.
		 *
		 * @since 0.1.0
		 *
		 * @param int           $view_id View ID.
		 * @param RenderRequest $request Embedding request.
		 */
		do_action( 'sw_postlist_before_render', $view_id, $request );

		$result = $this->build( $view_id, $request );

		/**
		 * Fires after a view was rendered (also on errors).
		 *
		 * @since 0.1.0
		 *
		 * @param int          $view_id View ID.
		 * @param RenderResult $result  Result.
		 */
		do_action( 'sw_postlist_after_render', $view_id, $result );
		return $result;
	}

	private function build( int $view_id, RenderRequest $request ): RenderResult {
		$view = $this->views->find( $view_id );
		if ( null === $view || ( 'publish' !== get_post_status( $view_id ) && ! ( $request->allow_draft && current_user_can( Capabilities::manage_capability() ) ) ) ) {
			return $this->failure( 'view_not_found', $view_id );
		}
		return $this->render_loaded( $view_id, $view, $request );
	}

	/**
	 * Renders a view document that was not stored (editor preview). Unsaved documents never reach visitors: callers
	 * must hold the management capability.
	 */
	public function render_document( ViewDocument $view, int $view_id, RenderRequest $request ): RenderResult {
		return $this->render_loaded( $view_id, $view, $request );
	}

	private function render_loaded( int $view_id, ViewDocument $view, RenderRequest $request ): RenderResult {
		$override_error = '';
		$effective      = $this->apply_overrides( $view, $request->overrides, $override_error );
		if ( null === $effective ) {
			return $this->failure( $override_error, $view_id );
		}
		$source = $this->sources->get( $effective->source_id );
		if ( null === $source ) {
			return $this->failure( 'source_missing', $view_id );
		}
		$layout_type = is_string( $effective->layout['type'] ?? null ) ? $effective->layout['type'] : 'grid';
		$container   = $this->compile( $source, $effective->container_template_id, TemplateRole::Container, $layout_type, $request->container_template );
		$entry       = $this->compile( $source, $effective->entry_template_id, TemplateRole::Entry, $layout_type, $request->entry_template );
		if ( null === $container || null === $entry ) {
			return $this->failure( 'template_invalid', $view_id );
		}
		// Without pagination the view shows its first page only; the page parameter is ignored.
		$paginated = 'none' !== ( $effective->pagination['mode'] ?? 'classic' );
		$page      = $paginated ? $request->page : 1;
		$filters   = $this->active_filters( $effective->filters, $source );
		$selection = ( new VisitorInputParser( $this->filter_types ) )->parse( $filters, InstanceRegistry::filter_state( $request->query_params, $request->instance_key ) );
		foreach ( $selection->problems as $problem ) {
			$this->logger->warning( 'filter_callback_failed', 'A filter parse callback failed.', array( 'filter' => $problem ) );
		}
		$visitor = $request->visitor ?? ( $selection->visitor->is_empty() ? null : $selection->visitor );
		$outcome = $this->query->run( $effective, $page, $visitor, $request->origin, $view_id );
		if ( null === $outcome->result ) {
			return $this->failure( (string) $outcome->error_code, $view_id );
		}
		$result     = $outcome->result;
		$definition = $this->layouts->get( $layout_type ) ?? $this->layouts->get( 'grid' );
		if ( ! $definition instanceof LayoutDefinition ) {
			return $this->failure( 'layout_missing', $view_id );
		}
		$items = $this->entries_html( $result->entries, $source, $entry['compiled'], $entry['scope'], $this->item_tag( $definition, $container['html'] ) );
		$items = $this->wrapped_items( $definition, $items, $effective );
		if ( null === $items ) {
			return $this->failure( 'layout_callback_failed', $view_id );
		}
		$nav         = $paginated ? $this->pagination->render( $request->instance_key, $page, $result->total, $effective->query->per_page, $result->has_more, $request->query_params ) : '';
		$filter_html = ( new FilterRenderer( $this->filter_types, $this->logger ) )->render( $request->instance_key, $filters, $selection, $request->query_params );
		$content     = $this->container_html( $container['compiled'], $effective, $result->total, $items, $nav, $filter_html );
		$css         = trim( $container['compiled']->css . "\n" . $entry['compiled']->css );
		$mode        = is_string( $effective->pagination['mode'] ?? null ) ? $effective->pagination['mode'] : 'classic';
		$dynamic     = array() !== $filters || in_array( $mode, array( 'ajax', 'load_more', 'infinite' ), true );
		$html        = $this->wrap( $view_id, $effective, $request, $container['scope'], $content, $definition, $dynamic );
		if ( $request->inline_css ) {
			$html = $this->inline_css(
				array(
					$container['scope'] => $container['compiled']->css,
					$entry['scope']     => $entry['compiled']->css,
				)
			) . $html;
		}
		FrontendAssets::enqueue();
		$final = new RenderResult( $html, $css, $result->total, $result->has_more, $page, count( $result->entries ) );
		/**
		 * Filters the markup of a rendered view.
		 *
		 * @since 0.1.0
		 *
		 * @param string       $html    Markup.
		 * @param int          $view_id View ID.
		 * @param RenderResult $result  Result with counts.
		 */
		$filtered = apply_filters( 'sw_postlist_render_html', $html, $view_id, $final );
		return new RenderResult( $this->string_or_default( $filtered, $html ), $css, $result->total, $result->has_more, $page, count( $result->entries ) );
	}

	/**
	 * @param array<string, mixed> $overrides  Embedding overrides.
	 * @param string               $error_code Receives the error code when null is returned.
	 * @return ViewDocument|null The effective view, null when an override is not allowed or invalid.
	 */
	private function apply_overrides( ViewDocument $view, array $overrides, string &$error_code ): ?ViewDocument {
		if ( array() === $overrides ) {
			return $view;
		}
		$raw = $view->to_array();
		foreach ( $overrides as $section => $values ) {
			if ( ! isset( self::OVERRIDABLE[ $section ] ) || ! is_array( $values ) ) {
				$error_code = 'override_not_allowed';
				return null;
			}
			foreach ( $values as $key => $value ) {
				if ( ! in_array( $key, self::OVERRIDABLE[ $section ], true ) ) {
					$error_code = 'override_not_allowed';
					return null;
				}
				$raw[ $section ][ $key ] = $value;
			}
		}
		$result = $this->validator->validate( $raw );
		if ( ! $result->is_valid() ) {
			$error_code = 'override_invalid';
			return null;
		}
		return $result->value();
	}

	/**
	 * The stored template or, without one (or when it vanished), the built-in one of the layout.
	 *
	 * @return array{compiled: CompiledTemplate, scope: string, html: string}|null
	 */
	private function compile( EntrySourceInterface $source, ?int $template_id, TemplateRole $role, string $layout_type, ?TemplateDocument $unsaved = null ): ?array {
		$document = $unsaved ?? ( null !== $template_id ? $this->templates->find( $template_id ) : null );
		$id       = null !== $unsaved ? 'preview-' . $role->value : ( $template_id ?? 0 );
		if ( null === $document || $document->role !== $role ) {
			$document = TemplateRole::Container === $role ? BuiltinTemplates::container( $layout_type ) : BuiltinTemplates::entry( $layout_type );
			$id       = TemplateRole::Container === $role ? BuiltinTemplates::container_id( $layout_type ) : BuiltinTemplates::entry_id( $layout_type );
		}
		$result = $this->engine->engine()->compile(
			$document->html,
			$document->css,
			$this->engine->context( $source, $document->role->value ),
			$id,
			$this->engine->generations( $source, $id )
		);
		if ( null === $result->template ) {
			$this->logger->warning(
				'template_invalid',
				'A template does not compile.',
				array(
					'template' => (string) $id,
					'errors'   => $result->error_codes(),
				)
			);
			return null;
		}
		return array(
			'compiled' => $result->template,
			'scope'    => $result->template->scope_class,
			'html'     => $document->html,
		);
	}

	/**
	 * `li` entries are only valid inside a list: a custom container template without `<ul>` or `<ol>` gets `div`
	 * entries, whatever the layout would prefer. A layout with its own wrapper callback is trusted to provide the list.
	 */
	private function item_tag( LayoutDefinition $definition, string $container_html ): string {
		if ( 'li' === $definition->item_tag && null === $definition->render_callback && 1 !== preg_match( '/<(ul|ol)[\s>]/i', $container_html ) ) {
			return 'div';
		}
		return $definition->item_tag;
	}

	/**
	 * @param list<\SW\Postlist\Domain\Source\Entry> $entries Entries of the page.
	 */
	private function entries_html( array $entries, EntrySourceInterface $source, CompiledTemplate $template, string $scope, string $tag ): string {
		if ( array() === $entries ) {
			return '<p class="swp-empty">' . esc_html__( 'No entries found.', 'sw-postlist' ) . '</p>';
		}
		$renderer = $this->engine->renderer();
		$html     = '';
		foreach ( $entries as $entry ) {
			$html .= '<' . $tag . ' class="swp-item ' . esc_attr( $scope ) . '">' . $renderer->render( $template, $this->engine->entry_resolver( $entry, $source ) ) . '</' . $tag . '>';
		}
		return $html;
	}

	private function container_html( CompiledTemplate $template, ViewDocument $view, ?int $total, string $items, string $navigation, string $filters ): string {
		$values = array(
			'view.title'      => $view->title,
			'view.count'      => $total,
			'view.entries'    => new TrustedHtml( $items ),
			'view.navigation' => new TrustedHtml( $navigation ),
			'view.filters'    => new TrustedHtml( $filters ),
		);
		$html   = $this->engine->renderer()->render( $template, new ArrayValueResolver( $values ) );
		// A container template that does not place the filters, the entries or the navigation gets them added.
		if ( ! self::uses( $template->ast, 'view.filters' ) ) {
			$html = $filters . $html;
		}
		if ( ! self::uses( $template->ast, 'view.entries' ) ) {
			$html .= $items;
		}
		if ( ! self::uses( $template->ast, 'view.navigation' ) ) {
			$html .= $navigation;
		}
		return $html;
	}

	/**
	 * Filters the visitor may use: only those the source supports. Others are ignored silently, they never widen
	 * anything and the source stays the authority.
	 *
	 * @param list<array<string, mixed>> $filters Configured filters.
	 * @return list<array<string, mixed>>
	 */
	private function active_filters( array $filters, EntrySourceInterface $source ): array {
		$fields     = $source->query_fields();
		$supported  = is_array( $fields['visitor_filters'] ?? null ) ? $fields['visitor_filters'] : array();
		$taxonomies = is_array( $fields['taxonomies'] ?? null ) ? $fields['taxonomies'] : array();
		$active     = array();
		foreach ( $filters as $config ) {
			$type = $config['type'] ?? null;
			$ok   = match ( $type ) {
				'taxonomy' => in_array( 'terms', $supported, true ) && in_array( $config['taxonomy'] ?? null, $taxonomies, true ),
				'author'   => in_array( 'author', $supported, true ),
				'search'   => in_array( 'search', $supported, true ),
				'sort'     => in_array( 'orderby', $supported, true ),
				default    => true,
			};
			if ( $ok ) {
				$active[] = $config;
			}
		}
		return $active;
	}

	/**
	 * @param array<int, mixed> $nodes AST nodes.
	 */
	public static function uses( array $nodes, string $name ): bool {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( in_array( $node[0] ?? null, array( 'v', 's' ), true ) && ( $node[1] ?? null ) === $name ) {
				return true;
			}
			if ( 's' === ( $node[0] ?? null ) && is_array( $node[3] ?? null ) && self::uses( $node[3], $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Third-party layouts may wrap the rendered entries; a failing or non-string callback is a neutral error.
	 */
	private function wrapped_items( LayoutDefinition $definition, string $items, ViewDocument $view ): ?string {
		if ( null === $definition->render_callback || ! is_callable( $definition->render_callback ) ) {
			return $items;
		}
		try {
			$wrapped = ( $definition->render_callback )( $items, $view->layout );
		} catch ( \Throwable $error ) {
			$this->logger->warning( 'layout_callback_failed', 'A layout render callback failed.', array( 'layout' => $definition->id ) );
			return null;
		}
		return is_string( $wrapped ) ? $wrapped : null;
	}

	private function wrap( int $view_id, ViewDocument $view, RenderRequest $request, string $container_scope, string $content, LayoutDefinition $definition, bool $dynamic ): string {
		$layout  = LayoutStyle::for_layout( $view->layout );
		$classes = array_merge( array( 'swp-view', $container_scope ), $layout['classes'] );
		// Views that reload dynamically (filters, AJAX pagination) or depend on a context hand a signed state to the
		// browser: the embedding overrides and the verified origin. The token is optional on the public route.
		$mode   = is_string( $view->pagination['mode'] ?? null ) ? $view->pagination['mode'] : 'classic';
		$origin = '';
		$token  = null;
		if ( 'none' !== $view->query->context && null !== $request->origin ) {
			$token = $this->signer->sign( $view_id, $request->instance_key, $request->origin, $request->overrides );
		} elseif ( $dynamic ) {
			$token = $this->signer->sign( $view_id, $request->instance_key, null, $request->overrides );
		}
		if ( null !== $token ) {
			$origin = ' data-swp-token="' . esc_attr( $token ) . '"';
		}
		if ( $dynamic ) {
			$origin .= ' data-swp-mode="' . esc_attr( $mode ) . '"';
			FrontendAssets::enqueue_module( 'dynamic' );
		}
		$module = '';
		if ( null !== $definition->module ) {
			FrontendAssets::enqueue_module( $definition->module );
			$module = ' data-swp-module="' . esc_attr( $definition->module ) . '"';
		}
		$options = array() === $layout['options'] ? '' : ' data-swp-options="' . esc_attr( (string) wp_json_encode( $layout['options'] ) ) . '"';
		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="swp-' . esc_attr( $request->instance_key ) . '" data-swp-view="' . esc_attr( (string) $view_id ) . '" data-swp-instance="' . esc_attr( $request->instance_key ) . '"'
			. $module . $options . $origin . ( '' !== $layout['style'] ? ' style="' . esc_attr( $layout['style'] ) . '"' : '' ) . '>' . $content . '</div>';
	}

	/**
	 * Template CSS goes out once per page and scope, directly before the first markup that needs it.
	 *
	 * @param array<string, string> $css_by_scope Scoped CSS by scope class.
	 */
	private function inline_css( array $css_by_scope ): string {
		$out = '';
		foreach ( $css_by_scope as $scope => $css ) {
			if ( '' === trim( $css ) || isset( self::$emitted_css[ $scope ] ) ) {
				continue;
			}
			self::$emitted_css[ $scope ] = true;
			// The CSS was scoped and validated by the engine (no url(), @import, escapes or markup characters).
			$out .= '<style id="swp-css-' . esc_attr( $scope ) . '">' . $css . '</style>';
		}
		return $out;
	}

	private function string_or_default( mixed $value, string $default ): string {
		return is_string( $value ) ? $value : $default;
	}

	private function failure( string $code, int $view_id ): RenderResult {
		$this->logger->warning(
			'render_failed',
			'A view could not be rendered.',
			array(
				'view'  => $view_id,
				'error' => $code,
			)
		);
		$note = current_user_can( Capabilities::manage_capability() )
			/* translators: %s: error code */
			? sprintf( esc_html__( 'This list cannot be displayed (%s).', 'sw-postlist' ), esc_html( $code ) )
			: esc_html__( 'This content is currently unavailable.', 'sw-postlist' );
		return RenderResult::error( $code, '<p class="swp-notice" role="status">' . $note . '</p>' );
	}

	/** Exposed for the preview route and tests: the origin read from the main query for the current request. */
	public function origin_from_main_query( ViewDocument $view ): ?ContextOrigin {
		return 'none' === $view->query->context ? null : ( new \SW\Postlist\Infrastructure\WordPress\MainQueryOriginReader() )->read();
	}
}
