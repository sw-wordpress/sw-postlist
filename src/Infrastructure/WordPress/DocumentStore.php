<?php
/**
 * Reads and writes the JSON document of a post, taking care of WordPress slashing and revisions.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Port\DocumentRepositoryException;

final class DocumentStore {

	/**
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $document Normalized document.
	 */
	public function write( int $post_id, array $document ): void {
		$json = wp_json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new DocumentRepositoryException( 'document_encode_failed', 'The document could not be encoded as JSON.' );
		}
		// update_post_meta() unslashes its input: slash first so quotes and backslashes survive unchanged.
		update_post_meta( $post_id, PostTypes::META_DOCUMENT, wp_slash( $json ) );
	}

	/**
	 * @return array<string, mixed>|null Decoded document or null when missing or corrupt.
	 */
	public function read( int $post_id ): ?array {
		$json = get_post_meta( $post_id, PostTypes::META_DOCUMENT, true );
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : null;
	}
}
