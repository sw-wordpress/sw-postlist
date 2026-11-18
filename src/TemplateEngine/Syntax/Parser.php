<?php
/**
 * Builds the syntax tree from lexer tokens and reports structural errors: unclosed or mismatched elements and sections.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Syntax;

use SW\Postlist\TemplateEngine\EngineProfile;
use SW\Postlist\TemplateEngine\Node\AttributeNode;
use SW\Postlist\TemplateEngine\Node\ElementNode;
use SW\Postlist\TemplateEngine\Node\Node;
use SW\Postlist\TemplateEngine\Node\SectionNode;
use SW\Postlist\TemplateEngine\Node\TextNode;
use SW\Postlist\TemplateEngine\Node\VariableNode;
use SW\Postlist\TemplateEngine\TemplateError;

final class Parser {

	/**
	 * Open containers: each frame is [kind, name/tag, inverted, offset, children].
	 *
	 * @var list<array{kind: string, name: string, inverted: bool, offset: int, attributes: list<AttributeNode>, children: list<Node>}>
	 */
	private array $stack = array();

	/** @var list<TemplateError> */
	private array $errors = array();

	private string $source = '';

	/**
	 * @param list<Token> $tokens Lexer tokens.
	 * @param string      $source Normalized source (for positions).
	 * @return array{nodes: list<Node>, errors: list<TemplateError>}
	 */
	public function parse( array $tokens, string $source ): array {
		$this->source = $source;
		$this->errors = array();
		$this->stack  = array( $this->frame( 'root', '', false, 0 ) );

		foreach ( $tokens as $token ) {
			switch ( $token->kind ) {
				case Token::TEXT:
					$this->append( new TextNode( $token->value, $token->offset ) );
					break;
				case Token::MUSTACHE:
					$this->mustache( $token );
					break;
				case Token::TAG_OPEN:
					$this->tag_open( $token );
					break;
				case Token::TAG_CLOSE:
					$this->tag_close( $token );
					break;
			}
		}
		for ( $open = count( $this->stack ); $open > 1; --$open ) {
			$frame = array_pop( $this->stack );
			if ( null === $frame ) {
				break;
			}
			if ( 'element' === $frame['kind'] ) {
				$this->error( 'unclosed_element', 'Element <' . $frame['name'] . '> is not closed.', $frame['offset'], array( 'tag' => $frame['name'] ) );
			} else {
				$this->error( 'unclosed_section', 'Section {{#' . $frame['name'] . '}} is not closed.', $frame['offset'], array( 'name' => $frame['name'] ) );
			}
		}
		$root = $this->stack[0];
		return array(
			'nodes'  => $root['children'],
			'errors' => $this->errors,
		);
	}

	/**
	 * @return array{kind: string, name: string, inverted: bool, offset: int, attributes: list<AttributeNode>, children: list<Node>}
	 */
	private function frame( string $kind, string $name, bool $inverted, int $offset ): array {
		return array(
			'kind'       => $kind,
			'name'       => $name,
			'inverted'   => $inverted,
			'offset'     => $offset,
			'attributes' => array(),
			'children'   => array(),
		);
	}

	/**
	 * @param array<string, mixed> $context Details.
	 */
	private function error( string $code, string $message, int $offset, array $context = array() ): void {
		list( $line, $column ) = Position::at( $this->source, $offset );
		$this->errors[]        = new TemplateError( $code, $message, $line, $column, $context );
	}

	private function append( Node $node ): void {
		$this->stack[ count( $this->stack ) - 1 ]['children'][] = $node;
	}

	private function mustache( Token $token ): void {
		$parsed = ExpressionParser::parse( $token->value, $token->offset );
		if ( isset( $parsed['error'] ) ) {
			$this->error( $parsed['code'], $parsed['error'], $token->offset );
			return;
		}
		$name = $parsed['name'];
		if ( '' === $token->sigil ) {
			$this->append( new VariableNode( $name, $parsed['chain'], $token->offset ) );
			return;
		}
		if ( array() !== $parsed['chain'] ) {
			// Reported once; the section still opens so that its closing tag does not add a second error.
			$this->error( 'section_formatter', 'Section tags do not take formatters.', $token->offset, array( 'name' => $name ) );
		}
		if ( '/' !== $token->sigil ) {
			$this->stack[] = $this->frame( 'section', $name, '^' === $token->sigil, $token->offset );
			return;
		}
		$this->close_section( $name, $token->offset );
	}

	private function close_section( string $name, int $offset ): void {
		$top = $this->stack[ count( $this->stack ) - 1 ];
		if ( 'section' !== $top['kind'] ) {
			$message = 'element' === $top['kind'] ? 'must be closed inside the same element' : 'does not match an open section';
			$code    = 'element' === $top['kind'] && $this->has_open_section( $name ) ? 'section_crosses_element' : 'unexpected_section_end';
			$this->error( $code, 'Closing {{/' . $name . '}} ' . $message . '.', $offset, array( 'name' => $name ) );
			return;
		}
		if ( $top['name'] !== $name ) {
			$this->error( 'unexpected_section_end', 'Closing {{/' . $name . '}} does not match the open section {{#' . $top['name'] . '}}.', $offset, array( 'name' => $name ) );
			return;
		}
		array_pop( $this->stack );
		$this->append( new SectionNode( $name, $top['inverted'], $top['children'], $top['offset'] ) );
	}

	private function has_open_section( string $name ): bool {
		foreach ( $this->stack as $frame ) {
			if ( 'section' === $frame['kind'] && $frame['name'] === $name ) {
				return true;
			}
		}
		return false;
	}

	private function tag_open( Token $token ): void {
		$attributes = array();
		foreach ( $token->attributes as $raw ) {
			$attributes[] = new AttributeNode( $raw->name, null === $raw->value ? null : $this->value_nodes( $raw->value ), $raw->offset );
		}
		$void = in_array( $token->value, EngineProfile::VOID_TAGS, true );
		if ( $token->self_closing && ! $void ) {
			$this->error( 'self_closing_element', '<' . $token->value . ' /> must be written with a closing tag.', $token->offset, array( 'tag' => $token->value ) );
			return;
		}
		if ( $void ) {
			$this->append( new ElementNode( $token->value, $attributes, array(), $token->offset ) );
			return;
		}
		$frame               = $this->frame( 'element', $token->value, false, $token->offset );
		$frame['attributes'] = $attributes;
		$this->stack[]       = $frame;
		if ( count( $this->stack ) > EngineProfile::MAX_DEPTH ) {
			$this->error( 'too_deep', 'Elements and sections are nested too deeply.', $token->offset );
		}
	}

	private function tag_close( Token $token ): void {
		$name = $token->value;
		if ( in_array( $name, EngineProfile::VOID_TAGS, true ) ) {
			$this->error( 'void_end_tag', '<' . $name . '> must not have a closing tag.', $token->offset, array( 'tag' => $name ) );
			return;
		}
		$top = $this->stack[ count( $this->stack ) - 1 ];
		if ( 'section' === $top['kind'] ) {
			$this->error( 'section_crosses_element', 'Section {{#' . $top['name'] . '}} must be closed inside the same element.', $token->offset, array( 'name' => $top['name'] ) );
			return;
		}
		if ( 'element' !== $top['kind'] || $top['name'] !== $name ) {
			$this->error( 'mismatched_end_tag', 'Closing </' . $name . '> does not match the open element.', $token->offset, array( 'tag' => $name ) );
			return;
		}
		array_pop( $this->stack );
		$this->append( new ElementNode( $name, $top['attributes'], $top['children'], $top['offset'] ) );
	}

	/**
	 * Converts attribute value tokens to nodes. Sections must open and close inside one attribute value.
	 *
	 * @param list<Token> $tokens Value tokens.
	 * @return list<Node>
	 */
	private function value_nodes( array $tokens ): array {
		$parser = new self();
		$result = $parser->parse( $tokens, $this->source );
		foreach ( $result['errors'] as $error ) {
			$this->errors[] = $error;
		}
		return $result['nodes'];
	}
}
