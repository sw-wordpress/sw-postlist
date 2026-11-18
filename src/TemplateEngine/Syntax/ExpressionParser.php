<?php
/**
 * Parses the inside of a placeholder tag: a dotted name and an optional chain of formatters with literal parameters,
 * for example `entry.title|truncate:60|upper` or `entry.date|date:"d.m.Y"`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Syntax;

use SW\Postlist\TemplateEngine\Node\FormatterCall;

final class ExpressionParser {

	public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[A-Za-z0-9_-]+)+$/';

	/**
	 * @return array{name: string, chain: list<FormatterCall>}|array{error: string, code: string}
	 */
	public static function parse( string $expression, int $offset ): array {
		$parts = self::split( $expression, '|' );
		if ( null === $parts ) {
			return array(
				'code'  => 'invalid_formatter_syntax',
				'error' => 'Unclosed string in the placeholder.',
			);
		}
		$name = trim( (string) array_shift( $parts ) );
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			return array(
				'code'  => 'invalid_placeholder',
				'error' => 'Invalid placeholder name "' . mb_substr( $name, 0, 60 ) . '".',
			);
		}
		$chain = array();
		foreach ( $parts as $part ) {
			$call = self::formatter( $part, $offset );
			if ( isset( $call['error'] ) ) {
				return $call;
			}
			$chain[] = $call['call'];
		}
		return array(
			'name'  => $name,
			'chain' => $chain,
		);
	}

	/**
	 * @return array{call: FormatterCall}|array{error: string, code: string}
	 */
	private static function formatter( string $part, int $offset ): array {
		$pieces = self::split( $part, ':' );
		if ( null === $pieces ) {
			return array(
				'code'  => 'invalid_formatter_syntax',
				'error' => 'Unclosed string in a formatter parameter.',
			);
		}
		$name = trim( (string) array_shift( $pieces ) );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
			return array(
				'code'  => 'invalid_formatter_syntax',
				'error' => 'Invalid formatter name "' . mb_substr( $name, 0, 40 ) . '".',
			);
		}
		$params = array();
		foreach ( $pieces as $piece ) {
			$literal = self::literal( trim( $piece ) );
			if ( null === $literal ) {
				return array(
					'code'  => 'invalid_parameter',
					'error' => 'Formatter parameters must be string, number or true/false literals.',
				);
			}
			$params[] = $literal[0];
		}
		return array( 'call' => new FormatterCall( $name, $params, $offset ) );
	}

	/**
	 * @return array{0: string|int|bool}|null A one element list so that falsy literals are distinguishable from failure.
	 */
	private static function literal( string $text ): ?array {
		if ( 'true' === $text || 'false' === $text ) {
			return array( 'true' === $text );
		}
		if ( 1 === preg_match( '/^-?\d{1,9}$/', $text ) ) {
			return array( (int) $text );
		}
		if ( strlen( $text ) >= 2 && '"' === $text[0] && '"' === $text[ strlen( $text ) - 1 ] ) {
			$inner = substr( $text, 1, -1 );
			if ( 1 === preg_match( '/(?<!\\\\)"/', $inner ) ) {
				return null;
			}
			return array(
				strtr(
					$inner,
					array(
						'\\"'  => '"',
						'\\\\' => '\\',
					)
				),
			);
		}
		return null;
	}

	/**
	 * Splits on a separator outside of double quoted strings; null when a string is not closed.
	 *
	 * @return list<string>|null
	 */
	private static function split( string $text, string $separator ): ?array {
		$parts   = array();
		$current = '';
		$quoted  = false;
		$length  = strlen( $text );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $text[ $index ];
			if ( $quoted && '\\' === $char && $index + 1 < $length ) {
				$current .= $char . $text[ $index + 1 ];
				++$index;
				continue;
			}
			if ( '"' === $char ) {
				$quoted = ! $quoted;
			} elseif ( ! $quoted && $char === $separator ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		if ( $quoted ) {
			return null;
		}
		$parts[] = $current;
		return $parts;
	}
}
