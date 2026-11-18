<?php
/**
 * Named maps for the formatter `map:"name"`: they hide values (for example the category "Uncategorized"), replace
 * values by other text and remove a common prefix ("Kategorie Feldstraße" becomes "Feldstraße"). Maps are configured
 * separately in the settings and used explicitly by a template, so one list can be shown differently in two templates.
 *
 * A map: `hide` (labels or slugs), `labels` (label or slug => text) and `strip_prefix`. Matching ignores case.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Map;

final class ValueMaps {

	public const MAX_MAPS = 30;

	public const MAX_ENTRIES = 100;

	public const MAX_LENGTH = 100;

	/**
	 * @param array<string, array{hide: list<string>, labels: array<string, string>, strip_prefix: string}> $maps Sanitized maps by name.
	 */
	public function __construct( private readonly array $maps = array() ) {
	}

	/**
	 * Only well-formed maps survive; types, lengths, counts and control characters are normalized.
	 *
	 * @return array<string, array{hide: list<string>, labels: array<string, string>, strip_prefix: string}>
	 */
	public static function sanitize( mixed $raw ): array {
		$clean = array();
		foreach ( is_array( $raw ) ? $raw : array() as $name => $map ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $name ) || ! is_array( $map ) || count( $clean ) >= self::MAX_MAPS ) {
				continue;
			}
			$hide = array();
			foreach ( is_array( $map['hide'] ?? null ) ? $map['hide'] : array() as $entry ) {
				$text = self::text( $entry );
				if ( '' !== $text && count( $hide ) < self::MAX_ENTRIES ) {
					$hide[ self::key( $text ) ] = $text;
				}
			}
			$labels = array();
			foreach ( is_array( $map['labels'] ?? null ) ? $map['labels'] : array() as $from => $to ) {
				$from = self::text( $from );
				$to   = self::text( $to );
				if ( '' !== $from && '' !== $to && count( $labels ) < self::MAX_ENTRIES ) {
					$labels[ self::key( $from ) ] = $to;
				}
			}
			$prefix         = is_string( $map['strip_prefix'] ?? null ) ? mb_substr( (string) preg_replace( '/[\x00-\x1f\x7f]+/u', '', $map['strip_prefix'] ), 0, 50 ) : '';
			$clean[ $name ] = array(
				'hide'         => array_values( $hide ),
				'labels'       => $labels,
				'strip_prefix' => '' === trim( $prefix ) ? '' : $prefix,
			);
		}
		return $clean;
	}

	/**
	 * @return list<string>
	 */
	public function names(): array {
		return array_keys( $this->maps );
	}

	public function has( string $name ): bool {
		return isset( $this->maps[ $name ] );
	}

	/**
	 * The text to show for a value, or null when the map hides it. An unknown map leaves the value untouched.
	 *
	 * @param string      $label Displayed text of the value.
	 * @param string|null $key   Optional stable key of the value (a term slug).
	 */
	public function apply( string $name, string $label, ?string $key = null ): ?string {
		$map = $this->maps[ $name ] ?? null;
		if ( null === $map ) {
			return $label;
		}
		$candidates = array( self::key( $label ) );
		if ( null !== $key && '' !== $key ) {
			$candidates[] = self::key( $key );
		}
		foreach ( $map['hide'] as $hidden ) {
			if ( in_array( self::key( $hidden ), $candidates, true ) ) {
				return null;
			}
		}
		foreach ( $candidates as $candidate ) {
			if ( isset( $map['labels'][ $candidate ] ) ) {
				return $map['labels'][ $candidate ];
			}
		}
		$prefix = $map['strip_prefix'];
		if ( '' !== $prefix && str_starts_with( $label, $prefix ) && mb_strlen( $label ) > mb_strlen( $prefix ) ) {
			return mb_substr( $label, mb_strlen( $prefix ) );
		}
		return $label;
	}

	private static function key( string $text ): string {
		return mb_strtolower( trim( $text ) );
	}

	private static function text( mixed $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return '';
		}
		return trim( mb_substr( (string) preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', (string) $value ), 0, self::MAX_LENGTH ) );
	}
}
