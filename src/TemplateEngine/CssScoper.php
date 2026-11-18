<?php
/**
 * Validates and scopes template CSS: external resources are rejected, every selector is confined to the template's
 * scope class (`.swp-tpl-{id}`). Declarations of style attributes are validated separately.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class CssScoper {

	private const FORBIDDEN = array(
		'url('         => 'url()',
		'image-set('   => 'image-set()',
		'expression('  => 'expression()',
		'javascript:'  => 'javascript:',
		'vbscript:'    => 'vbscript:',
		'behavior'     => 'behavior',
		'-moz-binding' => '-moz-binding',
		'@import'      => '@import',
		'@font-face'   => '@font-face',
		'@charset'     => '@charset',
		'@namespace'   => '@namespace',
		'<'            => '<',
		'\\'           => 'escape sequences',
		'{{'           => 'placeholders',
	);

	private const NESTING_AT_RULES = array( '@media', '@supports', '@container' );

	/**
	 * Reason when forbidden content was found, otherwise null.
	 */
	public static function find_forbidden( string $css ): ?string {
		$lower = strtolower( self::strip_comments( $css ) );
		foreach ( self::FORBIDDEN as $needle => $label ) {
			if ( str_contains( $lower, $needle ) ) {
				return $label;
			}
		}
		return null;
	}

	/**
	 * Validates the content of a style attribute (declarations only); returns a reason or null.
	 */
	public static function validate_declarations( string $css ): ?string {
		$forbidden = self::find_forbidden( $css );
		if ( null !== $forbidden ) {
			return $forbidden;
		}
		if ( str_contains( $css, '{' ) || str_contains( $css, '}' ) || str_contains( $css, '@' ) ) {
			return 'rules in style attribute';
		}
		return null;
	}

	/**
	 * Scopes a stylesheet below `$scope` (a class selector such as `.swp-tpl-7`).
	 *
	 * @throws \InvalidArgumentException With a safe reason if the CSS is not acceptable.
	 */
	public static function scope( string $css, string $scope ): string {
		if ( strlen( $css ) > EngineProfile::MAX_CSS_BYTES ) {
			throw new \InvalidArgumentException( 'stylesheet too long' );
		}
		$forbidden = self::find_forbidden( $css );
		if ( null !== $forbidden ) {
			throw new \InvalidArgumentException( 'forbidden: ' . $forbidden );
		}
		return trim( self::scope_block( self::strip_comments( $css ), $scope, 0 ) );
	}

	private static function scope_block( string $css, string $scope, int $depth ): string {
		if ( $depth > 4 ) {
			throw new \InvalidArgumentException( 'nesting too deep' );
		}
		$out    = '';
		$pos    = 0;
		$length = strlen( $css );
		while ( $pos < $length ) {
			$open = self::find_outside_strings( $css, '{', $pos );
			if ( null === $open ) {
				if ( '' !== trim( substr( $css, $pos ) ) ) {
					throw new \InvalidArgumentException( 'declarations outside of a rule' );
				}
				break;
			}
			$prelude = trim( substr( $css, $pos, $open - $pos ) );
			$close   = self::matching_brace( $css, $open );
			$body    = substr( $css, $open + 1, $close - $open - 1 );
			$pos     = $close + 1;
			if ( '' === $prelude ) {
				throw new \InvalidArgumentException( 'empty selector' );
			}
			if ( '@' === $prelude[0] ) {
				$at_name = strtolower( (string) preg_replace( '/[\s(].*$/s', '', $prelude ) );
				if ( in_array( $at_name, self::NESTING_AT_RULES, true ) ) {
					$out .= $prelude . '{' . trim( self::scope_block( $body, $scope, $depth + 1 ) ) . "}\n";
					continue;
				}
				if ( '@keyframes' === $at_name || '@-webkit-keyframes' === $at_name ) {
					$out .= $prelude . '{' . $body . "}\n";
					continue;
				}
				throw new \InvalidArgumentException( 'unsupported at-rule ' . $at_name );
			}
			if ( str_contains( $body, '{' ) ) {
				throw new \InvalidArgumentException( 'nested rules' );
			}
			$out .= self::scope_selectors( $prelude, $scope ) . '{' . trim( $body ) . "}\n";
		}
		return $out;
	}

	private static function scope_selectors( string $prelude, string $scope ): string {
		$selectors = array();
		foreach ( self::split_selectors( $prelude ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}
			$replaced    = preg_replace( '/^(:root|html|body)\b/i', $scope, $selector, 1, $count );
			$selectors[] = $count > 0 ? (string) $replaced : $scope . ' ' . $selector;
		}
		if ( array() === $selectors ) {
			throw new \InvalidArgumentException( 'empty selector' );
		}
		return implode( ', ', $selectors );
	}

	/**
	 * @return list<string>
	 */
	private static function split_selectors( string $prelude ): array {
		$parts   = array();
		$depth   = 0;
		$current = '';
		$length  = strlen( $prelude );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $prelude[ $index ];
			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$parts[] = $current;
		return $parts;
	}

	private static function matching_brace( string $css, int $open ): int {
		$depth  = 0;
		$length = strlen( $css );
		$quote  = null;
		for ( $index = $open; $index < $length; $index++ ) {
			$char = $css[ $index ];
			if ( null !== $quote ) {
				if ( $char === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return $index;
				}
			}
		}
		throw new \InvalidArgumentException( 'unbalanced braces' );
	}

	private static function find_outside_strings( string $css, string $needle, int $from ): ?int {
		$quote  = null;
		$length = strlen( $css );
		for ( $index = $from; $index < $length; $index++ ) {
			$char = $css[ $index ];
			if ( null !== $quote ) {
				if ( $char === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( $char === $needle ) {
				return $index;
			} elseif ( '}' === $char ) {
				throw new \InvalidArgumentException( 'unbalanced braces' );
			}
		}
		return null;
	}

	private static function strip_comments( string $css ): string {
		return (string) preg_replace( '~/\*.*?(\*/|$)~s', '', $css );
	}
}
