<?php
/**
 * Placeholder catalogue per entry source: the standard `entry.*` and `view.*` placeholders with type, description,
 * example and allowed template roles, extended through `sw_postlist_placeholders`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\TemplateEngine\DefinitionSchema;
use SW\Postlist\TemplateEngine\PlaceholderDefinition;
use SW\Postlist\TemplateEngine\Port\PlaceholderSchemaInterface;
use SW\Postlist\TemplateEngine\Syntax\ExpressionParser;
use SW\Postlist\TemplateEngine\ValueType;

final class PlaceholderRegistry {

	public const ROLE_ENTRY     = 'entry';
	public const ROLE_CONTAINER = 'container';

	/** @var list<array{code: string, message: string, source: string}> */
	private array $diagnostics = array();

	public function __construct( private readonly MetaAllowlist $meta ) {
	}

	/**
	 * @return list<array{code: string, message: string, source: string}>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	public function for_source( EntrySourceInterface $source ): PlaceholderSchemaInterface {
		$specs = $this->standard( $source );
		/**
		 * Filters the placeholders of an entry source.
		 *
		 * Each entry is `name => array( 'type' => 'text|number|date|list|url|html|flag|color|image',
		 * 'description' => string, 'example' => string, 'roles' => list<string> )`. Invalid entries are reported in the
		 * diagnostics and ignored.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, array<string, mixed>> $placeholders Placeholders by name.
		 * @param string                              $source_id    Entry source ID.
		 */
		$filtered = apply_filters( 'sw_postlist_placeholders', $specs, $source->id() );
		return new DefinitionSchema( $this->definitions( $filtered, $source->id() ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function standard( EntrySourceInterface $source ): array {
		$entry     = array( self::ROLE_ENTRY );
		$container = array( self::ROLE_CONTAINER );
		$specs     = array(
			'view.title'      => $this->spec( ValueType::Text, __( 'Title of the view', 'sw-postlist' ), 'Latest news' ),
			'view.count'      => $this->spec( ValueType::Number, __( 'Number of visible entries (after the start offset)', 'sw-postlist' ), '12', $container ),
			'view.navigation' => $this->spec( ValueType::Html, __( 'Pagination links', 'sw-postlist' ), '', $container ),
			'view.filters'    => $this->spec( ValueType::Html, __( 'Visitor filters', 'sw-postlist' ), '', $container ),
			'view.entries'    => $this->spec( ValueType::Html, __( 'The rendered entries', 'sw-postlist' ), '', $container ),
		);
		$fields    = $source->query_fields();
		if ( $source instanceof \SW\Postlist\Infrastructure\WordPress\PostTypeEntrySource ) {
			$specs += array(
				'entry.id'          => $this->spec( ValueType::Number, __( 'ID of the entry', 'sw-postlist' ), '42', $entry ),
				'entry.title'       => $this->spec( ValueType::Text, __( 'Title', 'sw-postlist' ), 'Hello world', $entry ),
				'entry.excerpt'     => $this->spec( ValueType::Text, __( 'Excerpt as plain text', 'sw-postlist' ), 'A short summary of the entry.', $entry ),
				'entry.content'     => $this->spec( ValueType::Html, __( 'Content, sanitized (shortcodes and dynamic blocks are not executed)', 'sw-postlist' ), '<p>Content</p>', $entry ),
				'entry.date'        => $this->spec( ValueType::Date, __( 'Publication date', 'sw-postlist' ), '2026-11-18', $entry ),
				'entry.modified'    => $this->spec( ValueType::Date, __( 'Date of the last modification', 'sw-postlist' ), '2026-11-19', $entry ),
				'entry.url'         => $this->spec( ValueType::Url, __( 'Permalink', 'sw-postlist' ), 'https://example.com/hello-world/', $entry ),
				'entry.image'       => $this->spec( ValueType::Image, __( 'Featured image', 'sw-postlist' ), '', $entry ),
				'entry.author.name' => $this->spec( ValueType::Text, __( 'Name of the author', 'sw-postlist' ), 'Alex Author', $entry ),
				'entry.author.url'  => $this->spec( ValueType::Url, __( 'Author archive', 'sw-postlist' ), 'https://example.com/author/alex/', $entry ),
			);
			foreach ( is_array( $fields['taxonomies'] ?? null ) ? $fields['taxonomies'] : array() as $taxonomy ) {
				if ( is_string( $taxonomy ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $taxonomy ) ) {
					/* translators: %s: taxonomy name */
					$specs[ 'entry.terms.' . $taxonomy ] = $this->spec( ValueType::List, sprintf( __( 'Terms of the taxonomy "%s"', 'sw-postlist' ), $taxonomy ), 'News, Events', $entry );
				}
			}
			foreach ( $this->meta->keys( $source->id() ) as $key ) {
				/* translators: %s: meta key */
				$specs[ 'entry.meta.' . $key ] = $this->spec( ValueType::Text, sprintf( __( 'Custom field "%s" (allowed key)', 'sw-postlist' ), $key ), '', $entry );
			}
			return $specs;
		}
		foreach ( $source->placeholders() as $name => $spec ) {
			$specs[ $name ] = $spec;
		}
		return $specs;
	}

	/**
	 * @param list<string> $roles Allowed roles, empty for all.
	 * @return array<string, mixed>
	 */
	private function spec( ValueType $type, string $description, string $example, array $roles = array() ): array {
		return array(
			'type'        => $type->value,
			'description' => $description,
			'example'     => $example,
			'roles'       => $roles,
		);
	}

	/**
	 * @return list<PlaceholderDefinition>
	 */
	private function definitions( mixed $specs, string $source_id ): array {
		if ( ! is_array( $specs ) ) {
			$this->diagnose( 'placeholders_not_array', 'The sw_postlist_placeholders filter must return an array.', $source_id );
			return array();
		}
		$definitions = array();
		foreach ( $specs as $name => $spec ) {
			$type = is_array( $spec ) && is_string( $spec['type'] ?? null ) ? ValueType::tryFrom( $spec['type'] ) : null;
			if ( ! is_string( $name ) || 1 !== preg_match( ExpressionParser::NAME_PATTERN, $name ) || null === $type ) {
				$this->diagnose( 'placeholder_invalid', 'Invalid placeholder registration "' . ( is_string( $name ) ? $name : (string) wp_json_encode( $name ) ) . '".', $source_id );
				continue;
			}
			$roles = array();
			foreach ( is_array( $spec['roles'] ?? null ) ? $spec['roles'] : array() as $role ) {
				if ( in_array( $role, array( self::ROLE_ENTRY, self::ROLE_CONTAINER ), true ) ) {
					$roles[] = (string) $role;
				}
			}
			$definitions[] = new PlaceholderDefinition(
				$name,
				$type,
				is_string( $spec['description'] ?? null ) ? $spec['description'] : '',
				is_string( $spec['example'] ?? null ) ? $spec['example'] : '',
				$roles
			);
		}
		return $definitions;
	}

	private function diagnose( string $code, string $message, string $source ): void {
		$this->diagnostics[] = array(
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}
}
