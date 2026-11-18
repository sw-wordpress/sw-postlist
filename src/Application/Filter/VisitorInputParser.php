<?php
/**
 * Turns the raw visitor input (`swp[key][f]`) into validated {@see VisitorFilters}. The same code serves the page
 * render, the form without JavaScript and the public REST route, so all of them accept exactly the same values.
 *
 * Only filters that the view configures are read, values are bounded and a visitor can never widen the selection:
 * the source intersects the result with the fixed criteria of the view.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Filter;

use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Source\VisitorFilters;
use SW\Postlist\Domain\View\QueryCriteria;

final class VisitorInputParser {

	public const MAX_VALUES = 20;

	public const MAX_TAXONOMIES = 6;

	public function __construct( private readonly FilterTypeRegistry $types ) {
	}

	/**
	 * URL parameter name of a configured filter.
	 *
	 * @param array<string, mixed> $config Filter configuration.
	 */
	public static function param_for( array $config ): string {
		$type = is_string( $config['type'] ?? null ) ? $config['type'] : '';
		return match ( $type ) {
			'taxonomy' => 'tax_' . ( is_string( $config['taxonomy'] ?? null ) ? $config['taxonomy'] : '' ),
			'author'   => 'author',
			'search'   => 'q',
			'sort'     => 'sort',
			default    => $type,
		};
	}

	/**
	 * @param list<array<string, mixed>> $filters Configured filters of the view (validated).
	 * @param mixed                      $input   Raw visitor input of the instance (`swp[key][f]`).
	 */
	public function parse( array $filters, mixed $input ): FilterSelection {
		$input    = is_array( $input ) ? $input : array();
		$terms    = array();
		$authors  = array();
		$search   = null;
		$orderby  = null;
		$order    = null;
		$params   = array();
		$problems = array();
		foreach ( $filters as $config ) {
			$type = is_string( $config['type'] ?? null ) ? $config['type'] : '';
			$name = self::param_for( $config );
			$raw  = $input[ $name ] ?? null;
			switch ( $type ) {
				case 'taxonomy':
					$ids = self::ids( $raw );
					if ( is_array( $config['terms'] ?? null ) && array() !== $config['terms'] ) {
						$ids = array_values( array_intersect( $ids, array_map( 'intval', $config['terms'] ) ) );
					}
					$params[ $name ] = array_map( 'strval', $ids );
					if ( array() !== $ids && is_string( $config['taxonomy'] ?? null ) ) {
						$terms[ $config['taxonomy'] ] = $ids;
					}
					break;
				case 'author':
					$ids             = self::ids( $raw );
					$params[ $name ] = array_map( 'strval', $ids );
					$authors         = array_values( array_unique( array_merge( $authors, $ids ) ) );
					break;
				case 'search':
					$text            = self::text( $raw );
					$params[ $name ] = null === $text ? array() : array( $text );
					$search          = $text ?? $search;
					break;
				case 'sort':
					$choice = self::sort( $raw, is_array( $config['options'] ?? null ) ? $config['options'] : array() );
					if ( null !== $choice ) {
						$params[ $name ] = array( $choice[0] . ':' . $choice[1] );
						$orderby         = $choice[0];
						$order           = $choice[1];
					} else {
						$params[ $name ] = array();
					}
					break;
				default:
					$extra = $this->third_party( $type, $config, $raw );
					if ( null === $extra ) {
						$problems[] = $type;
						break;
					}
					$params[ $name ] = self::strings( $raw );
					foreach ( $extra['terms'] as $taxonomy => $ids ) {
						$terms[ $taxonomy ] = array_values( array_unique( array_merge( $terms[ $taxonomy ] ?? array(), $ids ) ) );
					}
					$authors = array_values( array_unique( array_merge( $authors, $extra['authors'] ) ) );
					$search  = $extra['search'] ?? $search;
					$orderby = $extra['orderby'] ?? $orderby;
					$order   = $extra['order'] ?? $order;
			}
		}
		return new FilterSelection( new VisitorFilters( array_slice( $terms, 0, self::MAX_TAXONOMIES, true ), $authors, $search, $orderby, $order ), $params, $problems );
	}

	/**
	 * Positive integers from "3,4", [3, 4] or "3"; everything else is dropped.
	 *
	 * @return list<int>
	 */
	public static function ids( mixed $raw ): array {
		$ids = array();
		foreach ( self::strings( $raw, true ) as $item ) {
			if ( 1 === preg_match( '/^[1-9][0-9]{0,9}$/', $item ) ) {
				$ids[] = (int) $item;
			}
		}
		return array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_VALUES );
	}

	/**
	 * @return list<string>
	 */
	private static function strings( mixed $raw, bool $split = false ): array {
		$items = is_array( $raw ) ? array_values( $raw ) : array( $raw );
		$out   = array();
		foreach ( array_slice( $items, 0, self::MAX_VALUES ) as $item ) {
			if ( ! is_string( $item ) && ! is_int( $item ) ) {
				continue;
			}
			$item = (string) $item;
			foreach ( $split ? explode( ',', $item ) : array( $item ) as $part ) {
				$part = trim( $part );
				if ( '' !== $part && strlen( $part ) <= 200 ) {
					$out[] = $part;
				}
			}
		}
		return array_slice( $out, 0, self::MAX_VALUES );
	}

	/**
	 * Search text: first value, control characters removed, limited like the context search.
	 */
	private static function text( mixed $raw ): ?string {
		$value = is_array( $raw ) ? ( array_values( $raw )[0] ?? null ) : $raw;
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', $value ) );
		$value = mb_substr( $value, 0, ContextOrigin::MAX_SEARCH_LENGTH );
		return '' === $value ? null : $value;
	}

	/**
	 * @param array<mixed> $options Sort fields offered by the filter.
	 * @return array{0: string, 1: string}|null
	 */
	private static function sort( mixed $raw, array $options ): ?array {
		$value = is_array( $raw ) ? ( array_values( $raw )[0] ?? null ) : $raw;
		if ( ! is_string( $value ) ) {
			return null;
		}
		$parts = explode( ':', $value );
		$field = $parts[0];
		$dir   = $parts[1] ?? 'desc';
		if ( count( $parts ) > 2 || ! in_array( $field, $options, true ) || ! in_array( $field, QueryCriteria::ORDERBY, true ) || ! in_array( $dir, array( 'asc', 'desc' ), true ) ) {
			return null;
		}
		return array( $field, $dir );
	}

	/**
	 * Calls the callback of a third-party filter type and sanitizes what it returns; null when it failed.
	 *
	 * @param array<string, mixed> $config Filter configuration.
	 * @return array{terms: array<string, list<int>>, authors: list<int>, search: ?string, orderby: ?string, order: ?string}|null
	 */
	private function third_party( string $type, array $config, mixed $raw ): ?array {
		$definition = $this->types->get( $type );
		if ( null === $definition || ! is_callable( $definition->parse_callback ) ) {
			return null;
		}
		try {
			$result = ( $definition->parse_callback )( $config, self::strings( $raw ) );
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_array( $result ) ) {
			return null;
		}
		$terms = array();
		if ( is_array( $result['terms'] ?? null ) ) {
			foreach ( $result['terms'] as $taxonomy => $ids ) {
				if ( is_string( $taxonomy ) && 1 === preg_match( '/^[a-z0-9_-]{1,32}$/', $taxonomy ) && is_array( $ids ) ) {
					$clean = self::ids( array_map( 'strval', array_filter( $ids, 'is_int' ) ) );
					if ( array() !== $clean ) {
						$terms[ $taxonomy ] = $clean;
					}
				}
			}
		}
		$order = $result['order'] ?? null;
		$sort  = $result['orderby'] ?? null;
		return array(
			'terms'   => $terms,
			'authors' => is_array( $result['authors'] ?? null ) ? self::ids( array_map( 'strval', array_filter( $result['authors'], 'is_int' ) ) ) : array(),
			'search'  => self::text( $result['search'] ?? null ),
			'orderby' => is_string( $sort ) && in_array( $sort, QueryCriteria::ORDERBY, true ) ? $sort : null,
			'order'   => is_string( $order ) && in_array( $order, array( 'asc', 'desc' ), true ) ? $order : null,
		);
	}
}
