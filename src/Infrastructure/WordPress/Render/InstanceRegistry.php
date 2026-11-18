<?php
/**
 * Keys and URL state of view embeddings. Every embedding gets a key that is stable across reloads (explicit or derived
 * from view and occurrence), so two copies of one view page independently: `?swp[news-1][page]=2`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

final class InstanceRegistry {

	public const QUERY_VAR = 'swp';

	public const KEY_PATTERN = '/^[a-z0-9_-]{1,32}$/';

	public const MAX_PAGE = 10000;

	/** @var array<int, int> */
	private static array $occurrences = array();

	/** Test seam and request boundary: forget the occurrence counters. */
	public static function reset(): void {
		self::$occurrences = array();
	}

	/**
	 * An explicit, valid key wins; otherwise "view-{id}-{occurrence}" in order of appearance on the page.
	 */
	public static function key_for( int $view_id, ?string $explicit = null ): string {
		self::$occurrences[ $view_id ] = ( self::$occurrences[ $view_id ] ?? 0 ) + 1;
		if ( null !== $explicit && 1 === preg_match( self::KEY_PATTERN, $explicit ) ) {
			return $explicit;
		}
		return 'view-' . $view_id . '-' . self::$occurrences[ $view_id ];
	}

	/**
	 * The page requested for an instance; anything but a positive integer means page 1.
	 *
	 * @param array<string, mixed> $query Query parameters (usually $_GET, already unslashed).
	 */
	public static function page_from( array $query, string $key ): int {
		$state = $query[ self::QUERY_VAR ] ?? null;
		$mine  = is_array( $state ) && is_array( $state[ $key ] ?? null ) ? $state[ $key ] : array();
		$page  = $mine['page'] ?? null;
		if ( ( ! is_string( $page ) && ! is_int( $page ) ) || 1 !== preg_match( '/^[1-9][0-9]{0,4}$/', (string) $page ) ) {
			return 1;
		}
		return min( (int) $page, self::MAX_PAGE );
	}

	/**
	 * Query string for a page of one instance that keeps every other parameter (and the other instances' state).
	 *
	 * @param array<string, mixed> $query Current parameters.
	 */
	public static function query_for_page( array $query, string $key, int $page ): string {
		$state = is_array( $query[ self::QUERY_VAR ] ?? null ) ? $query[ self::QUERY_VAR ] : array();
		unset( $query[ self::QUERY_VAR ] );
		if ( $page > 1 ) {
			$state[ $key ]         = is_array( $state[ $key ] ?? null ) ? $state[ $key ] : array();
			$state[ $key ]['page'] = $page;
		} elseif ( is_array( $state[ $key ] ?? null ) ) {
			unset( $state[ $key ]['page'] );
			if ( array() === $state[ $key ] ) {
				unset( $state[ $key ] );
			}
		}
		if ( array() !== $state ) {
			$query[ self::QUERY_VAR ] = $state;
		}
		return array() === $query ? '?' : '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Raw visitor filter input of one instance (`swp[key][f]`).
	 *
	 * @param array<string, mixed> $query Query parameters.
	 * @return array<string, mixed>
	 */
	public static function filter_state( array $query, string $key ): array {
		$state = $query[ self::QUERY_VAR ] ?? null;
		$mine  = is_array( $state ) && is_array( $state[ $key ] ?? null ) ? $state[ $key ] : array();
		return is_array( $mine['f'] ?? null ) ? $mine['f'] : array();
	}

	/**
	 * Query string without the filters and the page of one instance; everything else (other instances, foreign
	 * parameters) stays.
	 *
	 * @param array<string, mixed> $query Current parameters.
	 */
	public static function query_reset( array $query, string $key ): string {
		$state = is_array( $query[ self::QUERY_VAR ] ?? null ) ? $query[ self::QUERY_VAR ] : array();
		unset( $query[ self::QUERY_VAR ], $state[ $key ] );
		if ( array() !== $state ) {
			$query[ self::QUERY_VAR ] = $state;
		}
		return array() === $query ? '?' : '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Hidden form fields that carry every parameter except the state of one instance, so that a GET form keeps the
	 * rest of the URL state.
	 *
	 * @param array<string, mixed> $query Current parameters.
	 * @return list<array{0: string, 1: string}> Name and value pairs.
	 */
	public static function foreign_fields( array $query, string $key ): array {
		$state = is_array( $query[ self::QUERY_VAR ] ?? null ) ? $query[ self::QUERY_VAR ] : array();
		unset( $state[ $key ] );
		unset( $query[ self::QUERY_VAR ] );
		if ( array() !== $state ) {
			$query[ self::QUERY_VAR ] = $state;
		}
		$fields = array();
		foreach ( explode( '&', http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) ) as $pair ) {
			if ( '' === $pair || ! str_contains( $pair, '=' ) ) {
				continue;
			}
			list( $name, $value ) = explode( '=', $pair, 2 );
			$fields[]             = array( rawurldecode( $name ), rawurldecode( $value ) );
		}
		return $fields;
	}

	/**
	 * Landmark label that stays unique when a page holds several embeddings: the first keeps the plain label, later
	 * ones with the generated key `view-{id}-{n}` get the number.
	 *
	 * @param string $label    Singular label, for example "Pagination".
	 * @param string $numbered Label with a number placeholder, for example "Pagination %d".
	 */
	public static function landmark_label( string $key, string $label, string $numbered ): string {
		if ( 1 === preg_match( '/^view-\d+-(\d+)$/', $key, $found ) && (int) $found[1] > 1 ) {
			return sprintf( $numbered, (int) $found[1] );
		}
		return $label;
	}
}
