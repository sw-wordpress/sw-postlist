<?php
/**
 * Compiled template: AST, scoped CSS and the scope class. Serializable for the AST cache; loading from a payload
 * verifies its structure, so a damaged cache entry leads to a recompilation instead of undefined behaviour.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class CompiledTemplate {

	/**
	 * @param list<array<int, mixed>> $ast         AST nodes (see AstBuilder).
	 * @param string                  $css         CSS already scoped to the template.
	 * @param string                  $scope_class Scope class without leading dot.
	 */
	public function __construct(
		public readonly array $ast,
		public readonly string $css,
		public readonly string $scope_class
	) {
	}

	/**
	 * @return array{engine: string, ast: list<array<int, mixed>>, css: string, scope: string}
	 */
	public function to_array(): array {
		return array(
			'engine' => EngineProfile::ENGINE_VERSION,
			'ast'    => $this->ast,
			'css'    => $this->css,
			'scope'  => $this->scope_class,
		);
	}

	/**
	 * @param array<string, mixed> $payload Stored payload.
	 */
	public static function from_array( array $payload ): ?self {
		if ( EngineProfile::ENGINE_VERSION !== ( $payload['engine'] ?? null ) || ! is_array( $payload['ast'] ?? null ) || ! is_string( $payload['css'] ?? null ) || ! is_string( $payload['scope'] ?? null ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/i', $payload['scope'] ) || ! self::valid_nodes( $payload['ast'], 0 ) ) {
			return null;
		}
		$ast = array();
		foreach ( $payload['ast'] as $node ) {
			$ast[] = $node;
		}
		return new self( $ast, $payload['css'], $payload['scope'] );
	}

	/**
	 * @param array<mixed> $nodes Candidate nodes.
	 */
	private static function valid_nodes( array $nodes, int $depth ): bool {
		if ( $depth > EngineProfile::MAX_DEPTH + 2 || ! array_is_list( $nodes ) ) {
			return false;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! array_is_list( $node ) || ! self::valid_node( $node, $depth ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<mixed> $node Candidate node.
	 */
	private static function valid_node( array $node, int $depth ): bool {
		$type = $node[0] ?? null;
		if ( 't' === $type ) {
			return 2 === count( $node ) && is_string( $node[1] );
		}
		if ( 's' === $type ) {
			return 4 === count( $node ) && self::valid_name( $node[1] ) && is_bool( $node[2] ) && is_array( $node[3] ) && self::valid_nodes( $node[3], $depth + 1 );
		}
		if ( 'v' === $type ) {
			return 5 === count( $node ) && self::valid_name( $node[1] ) && in_array( $node[2], array( 'text', 'attr', 'style' ), true ) && self::valid_chain( $node[3] ) && self::valid_type( $node[4] );
		}
		if ( 'u' === $type ) {
			return 5 === count( $node ) && is_string( $node[1] ) && 1 === preg_match( '/^[a-z-]{1,20}$/', $node[1] ) && self::valid_name( $node[2] ) && self::valid_chain( $node[3] ) && self::valid_type( $node[4] );
		}
		return false;
	}

	private static function valid_name( mixed $name ): bool {
		return is_string( $name ) && 1 === preg_match( '/^[a-z][a-z0-9_]*(\.[A-Za-z0-9_-]+)+$/', $name );
	}

	private static function valid_type( mixed $type ): bool {
		return is_string( $type ) && null !== ValueType::tryFrom( $type );
	}

	private static function valid_chain( mixed $chain ): bool {
		if ( ! is_array( $chain ) || ! array_is_list( $chain ) || count( $chain ) > 20 ) {
			return false;
		}
		foreach ( $chain as $call ) {
			if ( ! is_array( $call ) || 2 !== count( $call ) || ! is_string( $call[0] ?? null ) || ! is_array( $call[1] ?? null ) || ! array_is_list( $call[1] ) ) {
				return false;
			}
			foreach ( $call[1] as $param ) {
				if ( ! is_scalar( $param ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
