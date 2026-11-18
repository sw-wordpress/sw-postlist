<?php
/**
 * Placeholder schema backed by a list of definitions.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support\Engine;

use SW\Postlist\TemplateEngine\PlaceholderDefinition;
use SW\Postlist\TemplateEngine\Port\PlaceholderSchemaInterface;
use SW\Postlist\TemplateEngine\ValueType;

final class ArraySchema implements PlaceholderSchemaInterface {

	/** @var array<string, PlaceholderDefinition> */
	private array $definitions = array();

	/**
	 * @param list<PlaceholderDefinition> $definitions Definitions.
	 */
	public function __construct( array $definitions ) {
		foreach ( $definitions as $definition ) {
			$this->definitions[ $definition->name ] = $definition;
		}
	}

	/**
	 * The schema shared with the TypeScript contract tests (apps/admin/tests/templateEngine.test.ts).
	 */
	public static function contract(): self {
		return new self(
			array(
				new PlaceholderDefinition( 'entry.title', ValueType::Text, 'Title', 'Hello', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.excerpt', ValueType::Text, 'Excerpt', 'Short text', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.url', ValueType::Url, 'Link', 'https://example.com/', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.image', ValueType::Image, 'Image', '', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.price', ValueType::Number, 'Price', '9', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.featured', ValueType::Flag, 'Featured', '', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.color', ValueType::Color, 'Color', '#ff0000', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.terms.category', ValueType::List, 'Categories', '', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.meta.subtitle', ValueType::Text, 'Subtitle', '', array( 'entry' ) ),
				new PlaceholderDefinition( 'entry.body_html', ValueType::Html, 'Body', '', array( 'entry' ) ),
				new PlaceholderDefinition( 'view.title', ValueType::Text, 'View title', '' ),
				new PlaceholderDefinition( 'view.entries', ValueType::Html, 'Entries', '', array( 'container' ) ),
				new PlaceholderDefinition( 'view.count', ValueType::Number, 'Count', '', array( 'container' ) ),
			)
		);
	}

	public function find( string $name, string $role ): ?PlaceholderDefinition {
		$definition = $this->definitions[ $name ] ?? null;
		return null !== $definition && $definition->allows_role( $role ) ? $definition : null;
	}

	public function all( string $role ): array {
		return array_values( array_filter( $this->definitions, static fn ( PlaceholderDefinition $definition ): bool => $definition->allows_role( $role ) ) );
	}

	public function fingerprint( string $role ): string {
		return hash( 'sha256', (string) json_encode( array_map( static fn ( PlaceholderDefinition $definition ): array => $definition->to_array(), $this->all( $role ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.
	}
}
