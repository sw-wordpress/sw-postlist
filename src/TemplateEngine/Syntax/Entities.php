<?php
/**
 * Entity handling of static template text. Both the PHP and the TypeScript implementation decode exactly this set
 * and escape the five markup characters again, so normalized output is identical.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Syntax;

final class Entities {

	public const NAMED = array(
		'amp'    => '&',
		'lt'     => '<',
		'gt'     => '>',
		'quot'   => '"',
		'apos'   => "'",
		'nbsp'   => "\u{00A0}",
		'copy'   => "\u{00A9}",
		'reg'    => "\u{00AE}",
		'euro'   => "\u{20AC}",
		'hellip' => "\u{2026}",
		'mdash'  => "\u{2014}",
		'ndash'  => "\u{2013}",
		'laquo'  => "\u{00AB}",
		'raquo'  => "\u{00BB}",
		'bull'   => "\u{2022}",
		'middot' => "\u{00B7}",
		'trade'  => "\u{2122}",
		'auml'   => "\u{00E4}",
		'ouml'   => "\u{00F6}",
		'uuml'   => "\u{00FC}",
		'Auml'   => "\u{00C4}",
		'Ouml'   => "\u{00D6}",
		'Uuml'   => "\u{00DC}",
		'szlig'  => "\u{00DF}",
	);

	/**
	 * Static text as it appears in the compiled HTML: known entities decoded, the five markup characters escaped.
	 */
	public static function normalize( string $text ): string {
		return strtr(
			self::decode( $text ),
			array(
				'&' => '&amp;',
				'<' => '&lt;',
				'>' => '&gt;',
				'"' => '&quot;',
				"'" => '&apos;',
			)
		);
	}

	/**
	 * Decodes the known named entities and numeric character references; everything else is kept as written.
	 */
	public static function decode( string $text ): string {
		return (string) preg_replace_callback(
			'/&(#[xX][0-9a-fA-F]{1,6}|#\d{1,7}|[A-Za-z][A-Za-z0-9]{1,7});/',
			static function ( array $match ): string {
				$body = $match[1];
				if ( '#' !== $body[0] ) {
					return self::NAMED[ $body ] ?? $match[0];
				}
				$code = 'x' === strtolower( $body[1] ) ? (int) hexdec( substr( $body, 2 ) ) : (int) substr( $body, 1 );
				return $code >= 32 && $code <= 0x10FFFF && ( $code < 0xD800 || $code > 0xDFFF ) ? (string) mb_chr( $code, 'UTF-8' ) : $match[0];
			},
			$text
		);
	}
}
