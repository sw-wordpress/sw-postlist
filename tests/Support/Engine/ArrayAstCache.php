<?php
/**
 * In-memory AST cache that counts reads and writes.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support\Engine;

use SW\Postlist\TemplateEngine\Port\AstCacheInterface;

final class ArrayAstCache implements AstCacheInterface {

	/** @var array<string, array<string, mixed>> */
	public array $entries = array();

	public int $hits = 0;

	public int $writes = 0;

	public function get( string $key ): ?array {
		if ( isset( $this->entries[ $key ] ) ) {
			++$this->hits;
			return $this->entries[ $key ];
		}
		return null;
	}

	public function set( string $key, array $payload ): void {
		++$this->writes;
		$this->entries[ $key ] = $payload;
	}
}
