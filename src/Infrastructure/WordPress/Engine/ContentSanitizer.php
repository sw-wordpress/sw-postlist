<?php
/**
 * `entry.content` for 1.0: sanitized static content. Shortcodes and dynamic blocks are not executed, so a listed post
 * that embeds the same (or any other) Postlist can never trigger recursive rendering.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

final class ContentSanitizer {

	public const MAX_BYTES = 200000;

	/** Shortcodes of this plugin never appear in content output, whether registered or not. */
	private const OWN_SHORTCODES = array( 'sw_postlist' );

	public function sanitize( string $content ): string {
		$content = strlen( $content ) > self::MAX_BYTES ? (string) mb_strcut( $content, 0, self::MAX_BYTES, 'UTF-8' ) : $content;
		if ( has_blocks( $content ) ) {
			$content = $this->static_blocks( parse_blocks( $content ), 0 );
		}
		$content = strip_shortcodes( $content );
		$content = (string) preg_replace( '/\[\/?(?:' . implode( '|', self::OWN_SHORTCODES ) . ')(?:\s[^\]]*)?\]/', '', $content );
		$content = wpautop( $content );
		return wp_kses_post( $content );
	}

	/**
	 * Concatenates the HTML of static blocks; dynamic blocks (those with a render callback) are dropped.
	 *
	 * @param array<int|string, mixed> $blocks Parsed blocks.
	 */
	private function static_blocks( array $blocks, int $depth ): string {
		if ( $depth > 8 ) {
			return '';
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		$html     = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			if ( '' === $name ) {
				$html .= is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
				continue;
			}
			$type = $registry->get_registered( $name );
			if ( null !== $type && $type->is_dynamic() ) {
				continue;
			}
			$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
			if ( array() === $inner_blocks ) {
				$html .= is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
				continue;
			}
			$contents   = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
			$inner_html = '';
			$index      = 0;
			foreach ( $contents as $part ) {
				if ( is_string( $part ) ) {
					$inner_html .= $part;
				} elseif ( isset( $inner_blocks[ $index ] ) ) {
					$inner_html .= $this->static_blocks( array( $inner_blocks[ $index ] ), $depth + 1 );
					++$index;
				}
			}
			$html .= $inner_html;
		}
		return $html;
	}
}
