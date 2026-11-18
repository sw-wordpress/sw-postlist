<?php
/**
 * Splits a template into text, placeholder, start tag and end tag tokens. Attribute values are tokenized as well, so
 * later stages know in which context (text, attribute, URL, style) a placeholder appears.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Syntax;

use SW\Postlist\TemplateEngine\TemplateError;

final class Lexer {

	/** @var list<TemplateError> */
	private array $errors = array();

	private string $source = '';

	private int $pos = 0;

	private int $length = 0;

	/**
	 * @return array{tokens: list<Token>, errors: list<TemplateError>, source: string}
	 */
	public function tokenize( string $template ): array {
		$this->source = str_replace( array( "\r\n", "\r" ), "\n", $template );
		$this->errors = array();
		$this->pos    = 0;
		$this->length = strlen( $this->source );
		$tokens       = array();

		while ( $this->pos < $this->length ) {
			$lt   = strpos( $this->source, '<', $this->pos );
			$mu   = strpos( $this->source, '{{', $this->pos );
			$next = min( false === $lt ? $this->length : $lt, false === $mu ? $this->length : $mu );
			if ( $next > $this->pos ) {
				$tokens[]  = new Token( Token::TEXT, $this->pos, substr( $this->source, $this->pos, $next - $this->pos ) );
				$this->pos = $next;
			}
			if ( $this->pos >= $this->length ) {
				break;
			}
			if ( false !== $mu && $mu === $this->pos ) {
				$token = $this->mustache();
				if ( null !== $token ) {
					$tokens[] = $token;
				}
				continue;
			}
			$token = $this->tag();
			if ( null !== $token ) {
				$tokens[] = $token;
			}
		}
		return array(
			'tokens' => $tokens,
			'errors' => $this->errors,
			'source' => $this->source,
		);
	}

	/**
	 * @param array<string, mixed> $context Details.
	 */
	private function error( string $code, string $message, int $offset, array $context = array() ): void {
		list( $line, $column ) = Position::at( $this->source, $offset );
		$this->errors[]        = new TemplateError( $code, $message, $line, $column, $context );
	}

	private function mustache(): ?Token {
		$start = $this->pos;
		$end   = strpos( $this->source, '}}', $start + 2 );
		if ( false === $end ) {
			$this->error( 'unclosed_placeholder', 'Placeholder is not closed with "}}".', $start );
			$this->pos = $this->length;
			return null;
		}
		$this->pos = $end + 2;
		return $this->mustache_token( substr( $this->source, $start + 2, $end - $start - 2 ), $start );
	}

	private function mustache_token( string $inner, int $offset ): ?Token {
		$inner = trim( $inner );
		if ( '' !== $inner && in_array( $inner[0], array( '{', '!', '>', '&', '=', '<', '$' ), true ) ) {
			$this->error( 'unsupported_syntax', 'Unsupported template syntax "{{' . $inner[0] . '".', $offset );
			return null;
		}
		$sigil = '';
		if ( '' !== $inner && in_array( $inner[0], array( '#', '^', '/' ), true ) ) {
			$sigil = $inner[0];
			$inner = trim( substr( $inner, 1 ) );
		}
		if ( '' === $inner ) {
			$this->error( 'invalid_placeholder', 'Empty placeholder.', $offset );
			return null;
		}
		return new Token( Token::MUSTACHE, $offset, $inner, $sigil );
	}

	private function tag(): ?Token {
		$start = $this->pos;
		if ( 0 === substr_compare( $this->source, '<!--', $start, 4 ) ) {
			$end = strpos( $this->source, '-->', $start + 4 );
			if ( false === $end ) {
				$this->error( 'unclosed_comment', 'HTML comment is not closed.', $start );
				$this->pos = $this->length;
				return null;
			}
			// Comments are dropped: they could hide conditional comments or confuse later parsers.
			$this->pos = $end + 3;
			return null;
		}
		$next = $this->source[ $start + 1 ] ?? '';
		if ( '/' === $next ) {
			return $this->end_tag();
		}
		if ( ctype_alpha( $next ) ) {
			return $this->start_tag();
		}
		if ( '!' === $next || '?' === $next ) {
			$this->error( 'disallowed_markup', 'Doctype, CDATA and processing instructions are not allowed.', $start );
			$close     = strpos( $this->source, '>', $start );
			$this->pos = false === $close ? $this->length : $close + 1;
			return null;
		}
		++$this->pos;
		return new Token( Token::TEXT, $start, '<' );
	}

	private function end_tag(): ?Token {
		$start = $this->pos;
		if ( 1 !== preg_match( '/\G<\/([a-zA-Z][a-zA-Z0-9-]*)\s*>/', $this->source, $match, 0, $start ) ) {
			$this->error( 'malformed_tag', 'Malformed closing tag.', $start );
			$close     = strpos( $this->source, '>', $start );
			$this->pos = false === $close ? $this->length : $close + 1;
			return null;
		}
		$this->pos = $start + strlen( $match[0] );
		return new Token( Token::TAG_CLOSE, $start, strtolower( $match[1] ) );
	}

	private function start_tag(): ?Token {
		$start = $this->pos;
		if ( 1 !== preg_match( '/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $this->source, $match, 0, $start ) ) {
			++$this->pos;
			return new Token( Token::TEXT, $start, '<' );
		}
		$name      = strtolower( $match[1] );
		$this->pos = $start + strlen( $match[0] );
		$attrs     = array();
		$seen      = array();
		while ( true ) {
			$this->skip_whitespace();
			if ( $this->pos >= $this->length ) {
				$this->error( 'malformed_tag', 'Tag <' . $name . '> is not closed.', $start );
				return null;
			}
			$char = $this->source[ $this->pos ];
			if ( '>' === $char ) {
				++$this->pos;
				return new Token( Token::TAG_OPEN, $start, $name, '', $attrs, false );
			}
			if ( '/' === $char && '>' === ( $this->source[ $this->pos + 1 ] ?? '' ) ) {
				$this->pos += 2;
				return new Token( Token::TAG_OPEN, $start, $name, '', $attrs, true );
			}
			if ( 0 === substr_compare( $this->source, '{{', $this->pos, 2 ) ) {
				$this->error( 'placeholder_in_tag', 'Placeholders are only allowed inside attribute values.', $this->pos );
				$end       = strpos( $this->source, '}}', $this->pos );
				$this->pos = false === $end ? $this->length : $end + 2;
				continue;
			}
			$attr_start = $this->pos;
			if ( 1 !== preg_match( '/\G[^\s"\'>\/=]+/', $this->source, $attr_match, 0, $this->pos ) ) {
				$this->error( 'malformed_tag', 'Malformed attribute in <' . $name . '>.', $this->pos );
				$close     = strpos( $this->source, '>', $this->pos );
				$this->pos = false === $close ? $this->length : $close + 1;
				return null;
			}
			$attr_name  = strtolower( $attr_match[0] );
			$this->pos += strlen( $attr_match[0] );
			$this->skip_whitespace();
			$value = null;
			if ( '=' === ( $this->source[ $this->pos ] ?? '' ) ) {
				++$this->pos;
				$this->skip_whitespace();
				$value = $this->attribute_value( $name );
				if ( null === $value ) {
					return null;
				}
			}
			if ( isset( $seen[ $attr_name ] ) ) {
				$this->error( 'duplicate_attribute', 'Duplicate attribute "' . $attr_name . '".', $attr_start, array( 'attribute' => $attr_name ) );
				continue;
			}
			$seen[ $attr_name ] = true;
			$attrs[]            = new RawAttribute( $attr_name, $value, $attr_start );
		}
	}

	/**
	 * @return list<Token>|null
	 */
	private function attribute_value( string $tag ): ?array {
		$quote = $this->source[ $this->pos ] ?? '';
		if ( '"' === $quote || "'" === $quote ) {
			$end = strpos( $this->source, $quote, $this->pos + 1 );
			if ( false === $end ) {
				$this->error( 'malformed_tag', 'Unclosed attribute value in <' . $tag . '>.', $this->pos );
				$this->pos = $this->length;
				return null;
			}
			$raw_start = $this->pos + 1;
			$raw       = substr( $this->source, $raw_start, $end - $raw_start );
			$this->pos = $end + 1;
			return $this->split_value( $raw, $raw_start );
		}
		if ( 1 !== preg_match( '/\G[^\s"\'=<>`]+/', $this->source, $match, 0, $this->pos ) ) {
			$this->error( 'malformed_tag', 'Missing attribute value in <' . $tag . '>.', $this->pos );
			return null;
		}
		$raw_start  = $this->pos;
		$this->pos += strlen( $match[0] );
		if ( str_contains( $match[0], '{{' ) ) {
			// Reported once; the tag is kept (without the value) so the closing tag does not add a second error.
			$this->error( 'unquoted_placeholder', 'Attribute values with placeholders must be quoted.', $raw_start );
			return array();
		}
		return $this->split_value( $match[0], $raw_start );
	}

	/**
	 * Splits an attribute value into static text and placeholder tokens.
	 *
	 * @return list<Token>
	 */
	private function split_value( string $raw, int $base ): array {
		$tokens = array();
		$cursor = 0;
		$total  = strlen( $raw );
		while ( $cursor < $total ) {
			$open = strpos( $raw, '{{', $cursor );
			if ( false === $open ) {
				$tokens[] = new Token( Token::TEXT, $base + $cursor, substr( $raw, $cursor ) );
				break;
			}
			if ( $open > $cursor ) {
				$tokens[] = new Token( Token::TEXT, $base + $cursor, substr( $raw, $cursor, $open - $cursor ) );
			}
			$close = strpos( $raw, '}}', $open + 2 );
			if ( false === $close ) {
				$this->error( 'unclosed_placeholder', 'Placeholder is not closed with "}}".', $base + $open );
				break;
			}
			$token = $this->mustache_token( substr( $raw, $open + 2, $close - $open - 2 ), $base + $open );
			if ( null !== $token ) {
				$tokens[] = $token;
			}
			$cursor = $close + 2;
		}
		return $tokens;
	}

	private function skip_whitespace(): void {
		while ( $this->pos < $this->length && ctype_space( $this->source[ $this->pos ] ) ) {
			++$this->pos;
		}
	}
}
