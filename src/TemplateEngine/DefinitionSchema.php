<?php
/**
 * Placeholder schema backed by a list of definitions.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Port\PlaceholderSchemaInterface;

final class DefinitionSchema implements PlaceholderSchemaInterface {

	/** @var array<string, PlaceholderDefinition> */
	private array $definitions = array();

	/**
	 * @param list<PlaceholderDefinition> $definitions Definitions; a later definition replaces an earlier one of the same name.
	 */
	public function __construct( array $definitions ) {
		foreach ( $definitions as $definition ) {
			$this->definitions[ $definition->name ] = $definition;
		}
	}

	public function find( string $name, string $role ): ?PlaceholderDefinition {
		$definition = $this->definitions[ $name ] ?? null;
		return null !== $definition && $definition->allows_role( $role ) ? $definition : null;
	}

	public function all( string $role ): array {
		$list = array_values( array_filter( $this->definitions, static fn ( PlaceholderDefinition $definition ): bool => $definition->allows_role( $role ) ) );
		usort( $list, static fn ( PlaceholderDefinition $a, PlaceholderDefinition $b ): int => strcmp( $a->name, $b->name ) );
		return $list;
	}

	public function fingerprint( string $role ): string {
		return hash( 'sha256', (string) json_encode( array_map( static fn ( PlaceholderDefinition $definition ): array => $definition->to_array(), $this->all( $role ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free engine.
	}
}
