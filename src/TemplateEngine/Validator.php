<?php
/**
 * Checks a syntax tree against the engine profile (elements, attributes, URLs, styles), the placeholder schema and the
 * formatter type chains. Nothing here executes template content.
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
use SW\Postlist\TemplateEngine\Syntax\Position;

final class Validator {

	public const TARGETS = array( '_blank', '_self', '_parent', '_top' );

	/** @var list<TemplateError> */
	private array $errors = array();

	private string $source = '';

	private CompileContext $context;

	/**
	 * @param list<Node> $nodes   Syntax tree.
	 * @param string     $source  Normalized source, for error positions.
	 * @return list<TemplateError>
	 */
	public function validate( array $nodes, CompileContext $context, string $source ): array {
		$this->errors  = array();
		$this->source  = $source;
		$this->context = $context;
		$this->walk( $nodes );
		return $this->errors;
	}

	/**
	 * @param array<string, mixed> $details Additional details.
	 */
	private function add( string $code, string $message, int $offset, array $details = array() ): void {
		list( $line, $column ) = Position::at( $this->source, $offset );
		$this->errors[]        = new TemplateError( $code, $message, $line, $column, $details );
	}

	/**
	 * @param list<array{code: string, message: string, offset: int, context: array<string, mixed>}> $problems Typer findings.
	 */
	private function add_all( array $problems ): void {
		foreach ( $problems as $problem ) {
			$this->add( $problem['code'], $problem['message'], $problem['offset'], $problem['context'] );
		}
	}

	/**
	 * @param list<Node> $nodes Nodes of a text context.
	 */
	private function walk( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( $node instanceof VariableNode ) {
				$this->variable( $node, 'text' );
			} elseif ( $node instanceof SectionNode ) {
				$this->section( $node );
				$this->walk( $node->children );
			} elseif ( $node instanceof ElementNode ) {
				$this->element( $node );
			}
		}
	}

	private function section( SectionNode $node ): void {
		if ( null === $this->context->schema->find( $node->name, $this->context->role ) ) {
			$this->add( 'unknown_placeholder', 'Unknown placeholder "' . $node->name . '".', $node->offset, array( 'name' => $node->name ) );
		}
	}

	private function variable( VariableNode $node, string $usage ): ?ValueType {
		$typed = ChainTyper::type_of( $node, $this->context );
		$this->add_all( $typed['errors'] );
		$type = $typed['type'];
		if ( null === $type ) {
			return null;
		}
		if ( ! in_array( $type->value, ChainTyper::CONTEXT_TYPES[ $usage ], true ) ) {
			$code = ValueType::Html === $type ? 'html_only_in_text' : 'type_not_allowed_in_context';
			$this->add(
				$code,
				'A value of type ' . $type->value . ' cannot be used in this position (' . $usage . ').',
				$node->offset,
				array(
					'name'  => $node->name,
					'type'  => $type->value,
					'usage' => $usage,
				)
			);
		}
		return $type;
	}

	private function element( ElementNode $element ): void {
		if ( ! $this->context->profile->allows_tag( $element->tag ) ) {
			$this->add( 'disallowed_tag', 'The element <' . $element->tag . '> is not allowed.', $element->offset, array( 'tag' => $element->tag ) );
		} else {
			foreach ( $element->attributes as $attribute ) {
				$this->attribute( $element->tag, $attribute );
			}
		}
		$this->walk( $element->children );
	}

	private function attribute( string $tag, AttributeNode $attribute ): void {
		$name = $attribute->name;
		if ( str_starts_with( $name, 'on' ) ) {
			$this->add( 'event_handler_attribute', 'Event handler attributes are not allowed.', $attribute->offset, array( 'attribute' => $name ) );
			return;
		}
		if ( ! $this->context->profile->allows_attribute( $tag, $name ) ) {
			$this->add(
				'disallowed_attribute',
				'The attribute "' . $name . '" is not allowed on <' . $tag . '>.',
				$attribute->offset,
				array(
					'attribute' => $name,
					'tag'       => $tag,
				)
			);
			return;
		}
		$value = $attribute->value;
		if ( in_array( $name, EngineProfile::BOOLEAN_ATTRIBUTES, true ) ) {
			if ( null !== $value && ! self::is_static( $value ) ) {
				$this->add( 'boolean_attribute_dynamic', 'The attribute "' . $name . '" must be static.', $attribute->offset, array( 'attribute' => $name ) );
			}
			return;
		}
		if ( null === $value ) {
			$this->add( 'invalid_attribute_value', 'The attribute "' . $name . '" needs a value.', $attribute->offset, array( 'attribute' => $name ) );
			return;
		}
		if ( in_array( $name, EngineProfile::URL_ATTRIBUTES, true ) ) {
			$this->url_attribute( $name, $value, $attribute->offset );
		} elseif ( 'style' === $name ) {
			$this->style_attribute( $value, $attribute->offset );
		} elseif ( 'target' === $name || 'rel' === $name ) {
			$this->static_attribute( $name, $value, $attribute->offset );
		} else {
			$this->attribute_nodes( $value );
		}
	}

	/**
	 * @param list<Node> $nodes Attribute value nodes.
	 */
	private static function is_static( array $nodes ): bool {
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof TextNode ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param list<Node> $nodes Attribute value nodes.
	 */
	private static function static_text( array $nodes ): string {
		$text = '';
		foreach ( $nodes as $node ) {
			$text .= $node instanceof TextNode ? $node->text : '';
		}
		return $text;
	}

	/**
	 * @param list<Node> $nodes Value nodes.
	 */
	private function attribute_nodes( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( $node instanceof VariableNode ) {
				$this->variable( $node, 'attr' );
			} elseif ( $node instanceof SectionNode ) {
				$this->section( $node );
				$this->attribute_nodes( $node->children );
			}
		}
	}

	/**
	 * @param list<Node> $nodes  Value nodes.
	 */
	private function url_attribute( string $name, array $nodes, int $offset ): void {
		if ( self::is_static( $nodes ) ) {
			if ( ! UrlPolicy::is_safe( Entities::decode( self::static_text( $nodes ) ) ) ) {
				$this->add( 'unsafe_url', 'The URL in "' . $name . '" is not allowed (http, https, mailto, tel or relative only).', $offset, array( 'attribute' => $name ) );
			}
			return;
		}
		$significant = array_values(
			array_filter(
				$nodes,
				static fn ( Node $node ): bool => ! ( $node instanceof TextNode && '' === trim( $node->text ) )
			)
		);
		if ( 1 !== count( $significant ) || ! $significant[0] instanceof VariableNode ) {
			$this->add( 'url_attribute_mixed', 'The attribute "' . $name . '" takes a static URL or exactly one URL placeholder.', $offset, array( 'attribute' => $name ) );
			return;
		}
		$this->variable( $significant[0], 'url' );
	}

	/**
	 * @param list<Node> $nodes Value nodes.
	 */
	private function style_attribute( array $nodes, int $offset ): void {
		$css = '';
		foreach ( $nodes as $node ) {
			if ( $node instanceof TextNode ) {
				$css .= Entities::normalize( $node->text );
			} elseif ( $node instanceof VariableNode ) {
				$this->variable( $node, 'style' );
				$css .= '#000000';
			} else {
				$this->add( 'style_section', 'Sections are not allowed in style attributes.', $node instanceof SectionNode ? $node->offset : $offset );
			}
		}
		$reason = CssScoper::validate_declarations( Entities::decode( $css ) );
		if ( null !== $reason ) {
			$this->add( 'invalid_style', 'The style attribute contains forbidden content (' . $reason . ').', $offset, array( 'reason' => $reason ) );
		}
	}

	/**
	 * @param list<Node> $nodes Value nodes.
	 */
	private function static_attribute( string $name, array $nodes, int $offset ): void {
		if ( ! self::is_static( $nodes ) ) {
			$this->add( 'invalid_attribute_value', 'The attribute "' . $name . '" must be static.', $offset, array( 'attribute' => $name ) );
			return;
		}
		if ( 'target' === $name && ! in_array( self::static_text( $nodes ), self::TARGETS, true ) ) {
			$this->add( 'invalid_attribute_value', 'Invalid target value.', $offset, array( 'attribute' => $name ) );
		}
	}
}
