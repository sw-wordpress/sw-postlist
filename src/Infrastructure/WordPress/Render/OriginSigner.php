<?php
/**
 * Integrity of the minimal embed state that the browser hands back to the server for dynamic reloads: the origin
 * context and the overrides of the embedding (signed, bound to a view and an instance). The signature protects
 * integrity only; permissions are checked again on every fetch.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

use SW\Postlist\Domain\Source\ContextOrigin;

final class OriginSigner {

	private const VERSION = 1;

	/**
	 * @param array<string, mixed> $overrides Embedding overrides (shortcode or block attributes), validated again on use.
	 */
	public function sign( int $view_id, string $instance_key, ?ContextOrigin $origin, array $overrides = array() ): string {
		$data = array(
			'v'    => self::VERSION,
			'view' => $view_id,
			'key'  => $instance_key,
		);
		if ( null !== $origin ) {
			$data += array(
				'm' => $origin->mode,
				'i' => $origin->id,
				't' => $origin->taxonomy,
				's' => $origin->search,
			);
		}
		if ( array() !== $overrides ) {
			$data['o'] = $overrides;
		}
		$payload = $this->base64( (string) wp_json_encode( $data ) );
		return $payload . '.' . $this->base64( hash_hmac( 'sha256', $payload, wp_salt( 'sw_postlist_origin' ), true ) );
	}

	/**
	 * The origin when the token is intact and bound to this view and instance, otherwise null.
	 */
	public function verify( string $token, int $view_id, string $instance_key ): ?ContextOrigin {
		$state = $this->verify_embed( $token, $view_id, $instance_key );
		return null === $state ? null : $state['origin'];
	}

	/**
	 * The embed state when the token is intact and bound to this view and instance, otherwise null.
	 *
	 * @return array{origin: ?ContextOrigin, overrides: array<string, mixed>}|null
	 */
	public function verify_embed( string $token, int $view_id, string $instance_key ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || strlen( $token ) > 1000 ) {
			return null;
		}
		list( $payload, $signature ) = $parts;
		$expected                    = $this->base64( hash_hmac( 'sha256', $payload, wp_salt( 'sw_postlist_origin' ), true ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}
		$data = json_decode(
			(string) base64_decode( strtr( $payload, '-_', '+/' ), true ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL safe encoding of a signed token, not code obfuscation.
			true
		);
		if ( ! is_array( $data ) || self::VERSION !== ( $data['v'] ?? null ) || ( $data['view'] ?? null ) !== $view_id || ( $data['key'] ?? null ) !== $instance_key ) {
			return null;
		}
		$overrides = is_array( $data['o'] ?? null ) ? $data['o'] : array();
		return array(
			'origin'    => array_key_exists( 'm', $data ) ? $this->origin( $data ) : null,
			'overrides' => $overrides,
		);
	}

	/**
	 * @param array<mixed> $data Decoded payload.
	 */
	private function origin( array $data ): ?ContextOrigin {
		$id = $data['i'] ?? null;
		switch ( $data['m'] ?? null ) {
			case ContextOrigin::MODE_POST:
				return is_int( $id ) && $id > 0 ? ContextOrigin::post( $id ) : null;
			case ContextOrigin::MODE_AUTHOR:
				return is_int( $id ) && $id > 0 ? ContextOrigin::author( $id ) : null;
			case ContextOrigin::MODE_TERM:
				$taxonomy = $data['t'] ?? null;
				return is_int( $id ) && $id > 0 && is_string( $taxonomy ) && 1 === preg_match( '/^[a-z0-9_-]{1,32}$/', $taxonomy ) ? ContextOrigin::term( $taxonomy, $id ) : null;
			case ContextOrigin::MODE_SEARCH:
				return is_string( $data['s'] ?? null ) ? ContextOrigin::search( $data['s'] ) : null;
			default:
				return null;
		}
	}

	private function base64( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL safe encoding of a signed token, not code obfuscation.
	}
}
