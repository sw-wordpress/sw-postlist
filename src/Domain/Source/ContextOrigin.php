<?php
/**
 * Minimal, public origin of a context dependent view: the single post, a term archive, an author archive or a
 * search. Only IDs and bounded text; everything else is derived again from the current data.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Source;

final class ContextOrigin {

	public const MODE_POST   = 'post';
	public const MODE_TERM   = 'term';
	public const MODE_AUTHOR = 'author';
	public const MODE_SEARCH = 'search';

	public const MAX_SEARCH_LENGTH = 100;

	private function __construct(
		public readonly string $mode,
		public readonly ?int $id = null,
		public readonly ?string $taxonomy = null,
		public readonly ?string $search = null
	) {
	}

	public static function post( int $post_id ): self {
		return new self( self::MODE_POST, $post_id );
	}

	public static function term( string $taxonomy, int $term_id ): self {
		return new self( self::MODE_TERM, $term_id, $taxonomy );
	}

	public static function author( int $author_id ): self {
		return new self( self::MODE_AUTHOR, $author_id );
	}

	public static function search( string $text ): self {
		return new self( self::MODE_SEARCH, null, null, mb_substr( trim( $text ), 0, self::MAX_SEARCH_LENGTH ) );
	}
}
