<?php
/**
 * The built-in, whitelisted formatters (PLAN.md §5). WordPress specific formatters (`image`) are added by the host.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Formatter;

use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\FormatterSignature;
use SW\Postlist\TemplateEngine\ListItem;
use SW\Postlist\TemplateEngine\ParamSpec;
use SW\Postlist\TemplateEngine\Port\FormatterInterface;
use SW\Postlist\TemplateEngine\Syntax\Entities;
use SW\Postlist\TemplateEngine\TrustedHtml;
use SW\Postlist\TemplateEngine\ValueType;

final class BuiltinFormatters {

	public const CURRENCIES = array( 'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK' );

	/**
	 * @return list<FormatterInterface>
	 */
	public static function all(): array {
		$text_in = array( ValueType::Text );
		return array(
			new CallbackFormatter(
				'date',
				new FormatterSignature( array( ValueType::Date ), ValueType::Text, array( new ParamSpec( 'format', ParamSpec::TYPE_STRING, false, null, null, null, 50 ) ), 'Formats a date (PHP date format, site time zone).' ),
				static fn ( mixed $value, array $params, FormatContext $context ): ?string => $value instanceof \DateTimeImmutable ? $context->locale->format_date( $value, isset( $params[0] ) ? (string) $params[0] : null ) : null
			),
			new CallbackFormatter(
				'relative_date',
				new FormatterSignature( array( ValueType::Date ), ValueType::Text, array(), 'Distance to now, for example "3 days ago".' ),
				static fn ( mixed $value, array $params, FormatContext $context ): ?string => $value instanceof \DateTimeImmutable ? $context->locale->relative_date( $value, $context->clock->now() ) : null
			),
			new CallbackFormatter(
				'number',
				new FormatterSignature( array( ValueType::Number ), ValueType::Text, array( new ParamSpec( 'decimals', ParamSpec::TYPE_INT, false, 0, 10 ) ), 'Formats a number with the locale of the site.' ),
				static fn ( mixed $value, array $params, FormatContext $context ): ?string => is_int( $value ) || is_float( $value ) ? $context->locale->format_number( $value, isset( $params[0] ) ? (int) $params[0] : 0 ) : null
			),
			new CallbackFormatter(
				'currency',
				new FormatterSignature( array( ValueType::Number ), ValueType::Text, array( new ParamSpec( 'currency', ParamSpec::TYPE_STRING, true, null, null, self::CURRENCIES ) ), 'Formats an amount with a currency code.' ),
				static fn ( mixed $value, array $params, FormatContext $context ): ?string => ( is_int( $value ) || is_float( $value ) ) && isset( $params[0] ) ? $context->locale->format_currency( $value, (string) $params[0] ) : null
			),
			new CallbackFormatter(
				'truncate',
				new FormatterSignature( $text_in, ValueType::Text, array( new ParamSpec( 'length', ParamSpec::TYPE_INT, true, 1, 10000 ) ), 'Shortens text to a number of characters.' ),
				static function ( mixed $value, array $params ): string {
					$text   = is_string( $value ) ? $value : '';
					$length = (int) ( $params[0] ?? 0 );
					return mb_strlen( $text ) <= $length ? $text : rtrim( mb_substr( $text, 0, $length ) ) . "\u{2026}";
				}
			),
			new CallbackFormatter(
				'words',
				new FormatterSignature( $text_in, ValueType::Text, array( new ParamSpec( 'count', ParamSpec::TYPE_INT, true, 1, 1000 ) ), 'Shortens text to a number of words.' ),
				static function ( mixed $value, array $params ): string {
					$words = preg_split( '/\s+/u', trim( is_string( $value ) ? $value : '' ), -1, PREG_SPLIT_NO_EMPTY );
					$limit = (int) ( $params[0] ?? 0 );
					if ( false === $words || count( $words ) <= $limit ) {
						return false === $words ? '' : implode( ' ', $words );
					}
					return implode( ' ', array_slice( $words, 0, $limit ) ) . "\u{2026}";
				}
			),
			new CallbackFormatter(
				'strip_tags',
				new FormatterSignature( $text_in, ValueType::Text, array(), 'Removes HTML tags from text.' ),
				static fn ( mixed $value ): string => Entities::decode( (string) preg_replace( '/<\/?[a-zA-Z!][^>]*>/', '', is_string( $value ) ? $value : '' ) )
			),
			new CallbackFormatter(
				'nl2br',
				new FormatterSignature( $text_in, ValueType::Html, array(), 'Turns line breaks into <br> (text is escaped).' ),
				static fn ( mixed $value, array $params, FormatContext $context ): TrustedHtml => new TrustedHtml(
					str_replace( "\n", "<br>\n", $context->escaper->text( str_replace( array( "\r\n", "\r" ), "\n", is_string( $value ) ? $value : '' ) ) )
				)
			),
			new CallbackFormatter(
				'upper',
				new FormatterSignature( $text_in, ValueType::Text, array(), 'Upper case.' ),
				static fn ( mixed $value ): string => mb_strtoupper( is_string( $value ) ? $value : '' )
			),
			new CallbackFormatter(
				'lower',
				new FormatterSignature( $text_in, ValueType::Text, array(), 'Lower case.' ),
				static fn ( mixed $value ): string => mb_strtolower( is_string( $value ) ? $value : '' )
			),
			new CallbackFormatter(
				'capitalize',
				new FormatterSignature( $text_in, ValueType::Text, array(), 'Upper case first letter of every word.' ),
				static fn ( mixed $value ): string => (string) preg_replace_callback(
					'/(?<![\p{L}\p{N}])\p{L}/u',
					static fn ( array $match ): string => mb_strtoupper( $match[0] ),
					is_string( $value ) ? $value : ''
				)
			),
			new CallbackFormatter(
				'default',
				new FormatterSignature( array( ValueType::Text, ValueType::Number ), ValueType::Text, array( new ParamSpec( 'fallback', ParamSpec::TYPE_STRING, true ) ), 'Fallback text for an empty value.', true ),
				static function ( mixed $value, array $params ): string {
					if ( is_int( $value ) || is_float( $value ) ) {
						return (string) $value;
					}
					return is_string( $value ) && '' !== trim( $value ) ? $value : (string) ( $params[0] ?? '' );
				}
			),
			new CallbackFormatter(
				'join',
				new FormatterSignature( array( ValueType::List ), ValueType::Text, array( new ParamSpec( 'separator', ParamSpec::TYPE_STRING, false, null, null, null, 20 ) ), 'Joins the labels of a list.' ),
				static fn ( mixed $value, array $params ): string => implode( isset( $params[0] ) ? (string) $params[0] : ', ', self::labels( $value ) )
			),
			new CallbackFormatter(
				'link',
				new FormatterSignature( array( ValueType::List ), ValueType::Html, array( new ParamSpec( 'separator', ParamSpec::TYPE_STRING, false, null, null, null, 20 ) ), 'Renders the items of a list as links.' ),
				static function ( mixed $value, array $params, FormatContext $context ): TrustedHtml {
					$parts = array();
					foreach ( is_array( $value ) ? $value : array() as $item ) {
						if ( ! $item instanceof ListItem ) {
							continue;
						}
						$url     = null === $item->url ? null : $context->escaper->url( $item->url );
						$label   = $context->escaper->text( $item->label );
						$parts[] = null === $url || '' === $url ? $label : '<a href="' . $context->escaper->attribute( $url ) . '">' . $label . '</a>';
					}
					return new TrustedHtml( implode( $context->escaper->text( isset( $params[0] ) ? (string) $params[0] : ', ' ), $parts ) );
				}
			),
			new CallbackFormatter(
				'initials',
				new FormatterSignature( $text_in, ValueType::Text, array( new ParamSpec( 'count', ParamSpec::TYPE_INT, false, 1, 3 ) ), 'Upper case initials of the first words, for example "Anna Keller" becomes "AK".' ),
				static function ( mixed $value, array $params ): string {
					$words = preg_split( '/[\s\-]+/u', trim( is_string( $value ) ? $value : '' ), -1, PREG_SPLIT_NO_EMPTY );
					$out   = '';
					foreach ( array_slice( false === $words ? array() : $words, 0, isset( $params[0] ) ? (int) $params[0] : 2 ) as $word ) {
						$out .= mb_strtoupper( mb_substr( $word, 0, 1 ) );
					}
					return $out;
				}
			),
			new CallbackFormatter(
				'chips',
				new FormatterSignature( array( ValueType::List ), ValueType::Html, array( new ParamSpec( 'class', ParamSpec::TYPE_STRING, false, null, null, null, 40 ) ), 'Renders every label of a list as its own <span class="swp-chip"> (optional extra class, letters, digits, - and _).' ),
				static function ( mixed $value, array $params, FormatContext $context ): TrustedHtml {
					$extra = isset( $params[0] ) ? trim( (string) preg_replace( '/[^A-Za-z0-9_ -]/', '', (string) $params[0] ) ) : '';
					$class = '' === $extra ? 'swp-chip' : 'swp-chip ' . $extra;
					$out   = '';
					foreach ( self::labels( $value ) as $label ) {
						$out .= '<span class="' . $context->escaper->attribute( $class ) . '">' . $context->escaper->text( $label ) . '</span>';
					}
					return new TrustedHtml( $out );
				}
			),
			new CallbackFormatter(
				'esc_url',
				new FormatterSignature( array( ValueType::Url, ValueType::Text ), ValueType::Url, array(), 'Normalizes a URL; unsafe values become empty.' ),
				static fn ( mixed $value, array $params, FormatContext $context ): ?string => is_string( $value ) ? $context->escaper->url( $value ) : null
			),
		);
	}

	/**
	 * @return list<string>
	 */
	private static function labels( mixed $value ): array {
		$labels = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( $item instanceof ListItem ) {
				$labels[] = $item->label;
			}
		}
		return $labels;
	}
}
