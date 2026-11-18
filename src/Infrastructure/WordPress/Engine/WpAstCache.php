<?php
/**
 * AST cache on transients. The key prefix keeps every entry inside the plugin's cleanup namespace.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\TemplateEngine\Port\AstCacheInterface;

final class WpAstCache implements AstCacheInterface {

	public const PREFIX = 'sw_postlist_ast_';

	public function get( string $key ): ?array {
		$stored = get_transient( $this->name( $key ) );
		return is_array( $stored ) ? $stored : null;
	}

	public function set( string $key, array $payload ): void {
		set_transient( $this->name( $key ), $payload, 30 * DAY_IN_SECONDS );
	}

	private function name( string $key ): string {
		return self::PREFIX . substr( hash( 'sha256', $key ), 0, 40 );
	}
}
