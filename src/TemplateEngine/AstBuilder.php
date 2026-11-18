<?php
/**
 * Turns a validated syntax tree into the serializable AST: static markup is merged into strings, only placeholders and
 * sections remain dynamic. The AST is data, never code.
 *
 * Node formats:
 *  ['t', string]                                  static, already escaped markup
 *  ['v', name, usage, chain, type]                placeholder, usage text|attr|style, chain of [formatter, params]
 *  ['u', attribute, name, chain, type]            URL attribute with one placeholder (omitted when unsafe/empty)
 *  ['s', name, inverted, children]                section
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Node\AttributeNode;
use SW\Postlist\TemplateEngine\Node\ElementNode;
use SW\Postlist\TemplateEngine\Node\Node;
use SW\Postlist\TemplateEngine\Node\SectionNode;
use SW\Postlist\TemplateEngine\Node\TextNode;
use SW\Postlist\TemplateEngine\Node\VariableNode;
use SW\Postlist\TemplateEngine\Syntax\Entities;

final class AstBuilder {

	private CompileContext $context;

	/**
	 * @param list<Node> $nodes Validated syntax tree.
	 * @return list<array<int, mixed>>
	 */
	public function build( array $nodes, CompileContext $context ): array {
		$this->context = $context;
		return self::merge( $this->nodes( $nodes, 'text' ) );
	}

	/**
	 * @param list<Node> $nodes Nodes.
	 * @return list<array<int, mixed>>
	 */
	private function nodes( array $nodes, string $usage ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( $node instanceof TextNode ) {
				$out[] = array( 't', Entities::normalize( $node->text ) );
			} elseif ( $node instanceof VariableNode ) {
				$out[] = $this->variable( $node, $usage );
			} elseif ( $node instanceof SectionNode ) {
				$out[] = array( 's', $node->name, $node->inverted, self::merge( $this->nodes( $node->children, $usage ) ) );
			} elseif ( $node instanceof ElementNode ) {
				array_push( $out, ...$this->element( $node ) );
			}
		}
		return $out;
	}

	/**
	 * @return array<int, mixed>
	 */
	private function variable( VariableNode $node, string $usage ): array {
		$typed = ChainTyper::type_of( $node, $this->context );
		return array( 'v', $node->name, $usage, $this->chain( $node ), null === $typed['type'] ? 'text' : $typed['type']->value );
	}

	/**
	 * @return list<array{0: string, 1: list<string|int|bool>}>
	 */
	private function chain( VariableNode $node ): array {
		$chain = array();
		foreach ( $node->chain as $call ) {
			$chain[] = array( $call->name, $call->params );
		}
		return $chain;
	}

	/**
	 * @return list<array<int, mixed>>
	 */
	private function element( ElementNode $element ): array {
		$out    = array( array( 't', '<' . $element->tag ) );
		$target = null;
		$rel    = null;
		foreach ( $element->attributes as $attribute ) {
			if ( 'target' === $attribute->name ) {
				$target = $this->static_value( $attribute );
				continue;
			}
			if ( 'rel' === $attribute->name ) {
				$rel = $this->static_value( $attribute );
				continue;
			}
			array_push( $out, ...$this->attribute( $attribute ) );
		}
		if ( null !== $target ) {
			$out[] = array( 't', ' target="' . $target . '"' );
			if ( '_blank' === $target ) {
				$words = preg_split( '/\s+/', strtolower( trim( (string) $rel ) ), -1, PREG_SPLIT_NO_EMPTY );
				$rel   = implode( ' ', array_values( array_unique( array_merge( false === $words ? array() : $words, array( 'noopener', 'noreferrer' ) ) ) ) );
			}
		}
		if ( null !== $rel && '' !== $rel ) {
			$out[] = array( 't', ' rel="' . Entities::normalize( $rel ) . '"' );
		}
		$out[] = array( 't', '>' );
		if ( ! in_array( $element->tag, EngineProfile::VOID_TAGS, true ) ) {
			array_push( $out, ...$this->nodes( $element->children, 'text' ) );
			$out[] = array( 't', '</' . $element->tag . '>' );
		}
		return $out;
	}

	private function static_value( AttributeNode $attribute ): ?string {
		$text = '';
		foreach ( $attribute->value ?? array() as $node ) {
			$text .= $node instanceof TextNode ? $node->text : '';
		}
		return null === $attribute->value ? null : trim( Entities::decode( $text ) );
	}

	/**
	 * @return list<array<int, mixed>>
	 */
	private function attribute( AttributeNode $attribute ): array {
		$name  = $attribute->name;
		$value = $attribute->value;
		if ( null === $value ) {
			return array( array( 't', ' ' . $name ) );
		}
		if ( in_array( $name, EngineProfile::BOOLEAN_ATTRIBUTES, true ) ) {
			return array( array( 't', ' ' . $name ) );
		}
		if ( in_array( $name, EngineProfile::URL_ATTRIBUTES, true ) && ! self::all_static( $value ) ) {
			foreach ( $value as $node ) {
				if ( $node instanceof VariableNode ) {
					$typed = ChainTyper::type_of( $node, $this->context );
					return array( array( 'u', $name, $node->name, $this->chain( $node ), null === $typed['type'] ? 'url' : $typed['type']->value ) );
				}
			}
		}
		$usage = 'style' === $name ? 'style' : 'attr';
		$parts = $this->nodes( $value, $usage );
		if ( self::all_static( $value ) ) {
			$text = '';
			foreach ( $parts as $part ) {
				$text .= (string) $part[1];
			}
			return array( array( 't', ' ' . $name . '="' . $text . '"' ) );
		}
		return array_merge( array( array( 't', ' ' . $name . '="' ) ), $parts, array( array( 't', '"' ) ) );
	}

	/**
	 * @param list<Node> $nodes Nodes.
	 */
	private static function all_static( array $nodes ): bool {
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof TextNode ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Merges adjacent static strings.
	 *
	 * @param list<array<int, mixed>> $nodes AST nodes.
	 * @return list<array<int, mixed>>
	 */
	private static function merge( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			$last = count( $out ) - 1;
			if ( 't' === $node[0] && $last >= 0 && 't' === $out[ $last ][0] ) {
				$out[ $last ][1] .= $node[1];
				continue;
			}
			$out[] = $node;
		}
		return $out;
	}
}
