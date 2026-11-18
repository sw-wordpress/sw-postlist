<?php
/**
 * Renders a compiled template. Values are escaped for their output context; the engine calls no WordPress function.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Port\EscaperInterface;
use SW\Postlist\TemplateEngine\Port\FormatterRegistryInterface;
use SW\Postlist\TemplateEngine\Port\ValueResolverInterface;

final class Renderer {

	public function __construct(
		private readonly FormatterRegistryInterface $formatters,
		private readonly EscaperInterface $escaper,
		private readonly FormatContext $format_context
	) {
	}

	public function render( CompiledTemplate $template, ValueResolverInterface $resolver ): string {
		return $this->render_nodes( $template->ast, $resolver );
	}

	/**
	 * @param list<array<int, mixed>> $nodes AST nodes.
	 */
	private function render_nodes( array $nodes, ValueResolverInterface $resolver ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			switch ( $node[0] ) {
				case 't':
					$out .= (string) $node[1];
					break;
				case 'v':
					$out .= $this->variable( (string) $node[1], (string) $node[2], $this->chain( $node[3] ), $resolver );
					break;
				case 'u':
					$out .= $this->url_attribute( (string) $node[1], (string) $node[2], $this->chain( $node[3] ), $resolver );
					break;
				case 's':
					$truthy = self::truthy( $resolver->resolve( (string) $node[1] ) );
					if ( $truthy !== (bool) $node[2] ) {
						$out .= $this->render_nodes( is_array( $node[3] ) ? array_values( $node[3] ) : array(), $resolver );
					}
					break;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $chain Chain from the AST.
	 * @return list<array{0: string, 1: list<string|int|bool>}>
	 */
	private function chain( mixed $chain ): array {
		$calls = array();
		foreach ( is_array( $chain ) ? $chain : array() as $call ) {
			if ( is_array( $call ) && is_string( $call[0] ?? null ) && is_array( $call[1] ?? null ) ) {
				$params = array();
				foreach ( $call[1] as $param ) {
					if ( is_string( $param ) || is_int( $param ) || is_bool( $param ) ) {
						$params[] = $param;
					}
				}
				$calls[] = array( $call[0], $params );
			}
		}
		return $calls;
	}

	/**
	 * Empty and false values hide a section; the inverted section shows for them.
	 */
	public static function truthy( mixed $value ): bool {
		if ( null === $value || false === $value ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== $value && 0.0 !== $value;
		}
		if ( is_array( $value ) ) {
			return array() !== $value;
		}
		if ( $value instanceof TrustedHtml ) {
			return '' !== trim( $value->html );
		}
		return true;
	}

	/**
	 * Applies the formatter chain; a formatter returning null ends the chain with an empty value.
	 *
	 * @param list<array{0: string, 1: list<string|int|bool>}> $chain Chain.
	 */
	private function formatted( string $name, array $chain, ValueResolverInterface $resolver ): mixed {
		$value = $resolver->resolve( $name );
		foreach ( $chain as $call ) {
			$formatter = $this->formatters->get( $call[0] );
			if ( null === $formatter || ( null === $value && ! $formatter->signature()->accepts_empty ) ) {
				return null;
			}
			$value = $formatter->apply( $value, $call[1], $this->format_context );
		}
		return $value;
	}

	/**
	 * @param list<array{0: string, 1: list<string|int|bool>}> $chain Chain.
	 */
	private function variable( string $name, string $usage, array $chain, ValueResolverInterface $resolver ): string {
		$value = $this->formatted( $name, $chain, $resolver );
		if ( null === $value ) {
			return '';
		}
		if ( $value instanceof TrustedHtml ) {
			return 'text' === $usage ? self::clean( $value->html ) : $this->escaper->text( self::clean( $value->html ) );
		}
		$text = self::clean( $value instanceof \DateTimeImmutable ? $this->format_context->locale->format_date( $value, null ) : self::scalar_text( $value ) );
		if ( 'style' === $usage ) {
			return $this->escaper->color( $text ) ?? '';
		}
		if ( 'attr' === $usage ) {
			return $this->escaper->attribute( $text );
		}
		return $this->escaper->text( $text );
	}

	/**
	 * @param list<array{0: string, 1: list<string|int|bool>}> $chain Chain.
	 */
	private function url_attribute( string $attribute, string $name, array $chain, ValueResolverInterface $resolver ): string {
		$value = $this->formatted( $name, $chain, $resolver );
		if ( $value instanceof ImageValue ) {
			$value = $value->url;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$url = $this->escaper->url( self::clean( $value ) );
		return null === $url || '' === $url ? '' : ' ' . $attribute . '="' . $this->escaper->attribute( $url ) . '"';
	}

	/**
	 * Control characters (NUL, escape sequences …) have no place in HTML output, whatever the escaper does.
	 */
	public static function clean( string $text ): string {
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	}

	private static function scalar_text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}
		return '';
	}
}
