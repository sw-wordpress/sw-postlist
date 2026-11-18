<?php
/**
 * Storage of compiled ASTs.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

interface AstCacheInterface {

	/**
	 * @return array<string, mixed>|null Stored payload or null on a miss. The engine verifies the payload.
	 */
	public function get( string $key ): ?array;

	/**
	 * @param array<string, mixed> $payload Serializable compiled template.
	 */
	public function set( string $key, array $payload ): void;
}
