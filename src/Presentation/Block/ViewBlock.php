<?php
/**
 * Dynamic Gutenberg block `sw-postlist/view` (no build step). The server renders it with the shared view renderer.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Block;

use SW\Postlist\Infrastructure\WordPress\MainQueryOriginReader;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\RenderRequest;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;

final class ViewBlock {

	public const NAME = 'sw-postlist/view';

	public function __construct( private readonly ViewRenderer $renderer ) {
	}

	public function register(): void {
		register_block_type(
			SW_POSTLIST_DIR . 'blocks/view',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$view_id = is_numeric( $attributes['viewId'] ?? null ) ? (int) $attributes['viewId'] : 0;
		if ( $view_id <= 0 ) {
			return '';
		}
		$explicit = is_string( $attributes['instanceKey'] ?? null ) && '' !== $attributes['instanceKey'] ? $attributes['instanceKey'] : null;
		$key      = InstanceRegistry::key_for( $view_id, $explicit );
		$query    = PostlistShortcode::current_query();
		$request  = new RenderRequest(
			$key,
			InstanceRegistry::page_from( $query, $key ),
			null,
			( new MainQueryOriginReader() )->read(),
			PostlistShortcode::overrides(
				array(
					'layout'     => is_string( $attributes['layout'] ?? null ) ? $attributes['layout'] : '',
					'columns'    => is_string( $attributes['columns'] ?? null ) ? $attributes['columns'] : '',
					'per_page'   => is_string( $attributes['perPage'] ?? null ) ? $attributes['perPage'] : '',
					'orderby'    => '',
					'order'      => '',
					'pagination' => '',
				)
			),
			false,
			true,
			$query
		);
		return $this->renderer->render( $view_id, $request )->html;
	}
}
