<?php
/**
 * Converts byte offsets of a source text to 1-based line and column numbers.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Syntax;

final class Position {

	/**
	 * @return array{0: int, 1: int} [line, column]
	 */
	public static function at( string $source, int $offset ): array {
		$offset = max( 0, min( $offset, strlen( $source ) ) );
		$before = substr( $source, 0, $offset );
		$line   = substr_count( $before, "\n" ) + 1;
		$last   = strrpos( $before, "\n" );
		$tail   = false === $last ? $before : substr( $before, $last + 1 );
		return array( $line, mb_strlen( $tail ) + 1 );
	}
}
