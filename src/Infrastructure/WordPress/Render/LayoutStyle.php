<?php
/**
 * Layout options of a view as CSS custom properties and classes on the wrapper. Values are validated integers, enum
 * members or palette slugs, never free text.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

final class LayoutStyle {

	public const DEFAULT_COLUMNS   = array(
		'desktop' => 3,
		'tablet'  => 2,
		'mobile'  => 1,
	);
	public const DEFAULT_GAP       = array(
		'desktop' => 24,
		'tablet'  => 16,
		'mobile'  => 12,
	);
	public const DEFAULT_FONT_SIZE = array(
		'desktop' => 100,
		'tablet'  => 100,
		'mobile'  => 100,
	);
	public const DEFAULT_SLIDES    = array(
		'desktop' => 3,
		'tablet'  => 2,
		'mobile'  => 1,
	);

	/** Defaults of the slider options that are handed to the frontend module. */
	public const SLIDER_DEFAULTS = array(
		'autoplay' => false,
		'interval' => 6000,
		'loop'     => false,
		'arrows'   => true,
		'dots'     => true,
	);

	/**
	 * @param array<string, mixed> $layout Validated layout section of a view.
	 * @return array{style: string, classes: list<string>, options: array<string, bool|int>}
	 */
	public static function for_layout( array $layout ): array {
		$type    = is_string( $layout['type'] ?? null ) ? $layout['type'] : 'grid';
		$classes = array( 'swp-layout-' . preg_replace( '/[^a-z0-9_-]/', '', $type ) );
		$vars    = array();
		foreach ( array(
			'columns'   => array( 'cols', self::DEFAULT_COLUMNS ),
			'gap'       => array( 'gap', self::DEFAULT_GAP ),
			'font_size' => array( 'font', self::DEFAULT_FONT_SIZE ),
			'slides'    => array( 'slides', self::DEFAULT_SLIDES ),
		) as $key => $spec ) {
			$values = is_array( $layout[ $key ] ?? null ) ? $layout[ $key ] : array();
			foreach ( $spec[1] as $device => $default ) {
				$number                                      = isset( $values[ $device ] ) && is_int( $values[ $device ] ) ? $values[ $device ] : $default;
				$vars[ '--swp-' . $spec[0] . '-' . $device ] = (string) $number . ( 'gap' === $key ? 'px' : ( 'font_size' === $key ? '%' : '' ) );
			}
		}
		$ratio = is_string( $layout['aspect_ratio'] ?? null ) ? $layout['aspect_ratio'] : 'auto';
		if ( 'auto' !== $ratio && 1 === preg_match( '#^\d{1,2}/\d{1,2}$#', $ratio ) ) {
			$vars['--swp-aspect'] = str_replace( '/', ' / ', $ratio );
		}
		$accent = is_string( $layout['accent'] ?? null ) ? $layout['accent'] : null;
		if ( null !== $accent && 1 === preg_match( '/^[a-z0-9-]{1,40}$/', $accent ) ) {
			// theme.json palette colour with the plugin default as fallback.
			$vars['--swp-accent'] = 'var(--wp--preset--color--' . $accent . ', #2271b1)';
		}
		foreach ( is_array( $layout['hidden'] ?? null ) ? $layout['hidden'] : array() as $device => $hidden ) {
			if ( true === $hidden && in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
				$classes[] = 'swp-hide-' . $device;
			}
		}
		if ( 'slider' === $type ) {
			$options = array();
			foreach ( self::SLIDER_DEFAULTS as $option => $default ) {
				$given              = $layout[ $option ] ?? $default;
				$options[ $option ] = is_bool( $default ) ? true === $given : ( is_int( $given ) ? $given : $default );
			}
			$classes[] = 'swp-has-options';
		}
		$style = '';
		foreach ( $vars as $name => $value ) {
			$style .= $name . ':' . $value . ';';
		}
		return array(
			'style'   => $style,
			'classes' => $classes,
			'options' => $options ?? array(),
		);
	}
}
