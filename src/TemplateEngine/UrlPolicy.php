<?php
/**
 * Decides which static URLs templates may contain: http, https, mailto, tel and relative references.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class UrlPolicy {

	public const SCHEMES = array( 'http', 'https', 'mailto', 'tel' );

	public static function is_safe( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || 1 === preg_match( '/[\x00-\x20\x7f\\\\<>"\'`]/', $url ) ) {
			return false;
		}
		if ( 1 === preg_match( '/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $match ) ) {
			return in_array( strtolower( $match[1] ), self::SCHEMES, true );
		}
		// Protocol-relative URLs would leave the site silently.
		return ! str_starts_with( $url, '//' );
	}
}
