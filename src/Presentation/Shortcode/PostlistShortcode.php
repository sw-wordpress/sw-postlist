<?php
/**
 * `[sw_postlist id="12" key="news" layout="grid" columns="3" per_page="6" pagination="none"]`
 *
 * The attributes are embedding overrides: they are merged into the stored view and validated by the same schema.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Shortcode;

use SW\Postlist\Infrastructure\WordPress\MainQueryOriginReader;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\RenderRequest;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;

final class PostlistShortcode {

	public const TAG = 'sw_postlist';

	public function __construct( private readonly ViewRenderer $renderer ) {
	}

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render( $atts ): string {
		$atts    = shortcode_atts(
			array(
				'id'         => '0',
				'key'        => '',
				'layout'     => '',
				'columns'    => '',
				'per_page'   => '',
				'orderby'    => '',
				'order'      => '',
				'pagination' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$view_id = (int) $atts['id'];
		if ( $view_id <= 0 ) {
			return '';
		}
		$key     = InstanceRegistry::key_for( $view_id, '' === $atts['key'] ? null : $atts['key'] );
		$query   = self::current_query();
		$request = new RenderRequest(
			$key,
			InstanceRegistry::page_from( $query, $key ),
			null,
			( new MainQueryOriginReader() )->read(),
			self::overrides( $atts ),
			false,
			true,
			$query
		);
		return $this->renderer->render( $view_id, $request )->html;
	}

	/**
	 * @param array<string, string> $atts Attributes with defaults.
	 * @return array<string, array<string, mixed>>
	 */
	public static function overrides( array $atts ): array {
		$overrides = array();
		if ( '' !== $atts['layout'] ) {
			$overrides['layout']['type'] = $atts['layout'];
		}
		if ( '' !== $atts['columns'] ) {
			$numbers = array_map( 'intval', explode( ',', $atts['columns'] ) );
			$devices = array( 'desktop', 'tablet', 'mobile' );
			foreach ( $numbers as $index => $number ) {
				if ( isset( $devices[ $index ] ) ) {
					$overrides['layout']['columns'][ $devices[ $index ] ] = $number;
				}
			}
		}
		if ( '' !== $atts['per_page'] ) {
			$overrides['query']['per_page'] = ctype_digit( $atts['per_page'] ) ? (int) $atts['per_page'] : 0;
		}
		foreach ( array( 'orderby', 'order' ) as $key ) {
			if ( '' !== $atts[ $key ] ) {
				$overrides['query'][ $key ] = $atts[ $key ];
			}
		}
		if ( '' !== $atts['pagination'] ) {
			$overrides['pagination']['mode'] = $atts['pagination'];
		}
		return $overrides;
	}

	/**
	 * The current request's query parameters, for pagination links that preserve the rest of the URL state.
	 *
	 * @return array<string, mixed>
	 */
	public static function current_query(): array {
		// Read-only, no state change: links are rebuilt from public URL parameters and never trusted.
		return map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by map_deep, read-only.
	}
}
