<?php
/**
 * WordPress adapter of the engine's escaper: esc_html/esc_attr and a protocol allowlist for URLs.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\TemplateEngine\Port\EscaperInterface;
use SW\Postlist\TemplateEngine\UrlPolicy;

final class WpEscaper implements EscaperInterface {

	public function text( string $value ): string {
		return esc_html( $value );
	}

	public function attribute( string $value ): string {
		return esc_attr( $value );
	}

	public function url( string $value ): ?string {
		$value = trim( $value );
		if ( ! UrlPolicy::is_safe( $value ) ) {
			return null;
		}
		$url = esc_url_raw( $value, UrlPolicy::SCHEMES );
		return '' === $url ? null : $url;
	}

	public function color( string $value ): ?string {
		return 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : null;
	}
}
