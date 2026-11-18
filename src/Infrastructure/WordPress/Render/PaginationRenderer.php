<?php
/**
 * Classic, server rendered pagination with plain links: works without JavaScript and keeps the state of every other
 * embedding on the page.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

final class PaginationRenderer {

	/**
	 * @param string               $key      Instance key.
	 * @param int                  $page     Current page.
	 * @param int|null             $total    Exact visible total or null for a continuation source.
	 * @param int                  $per_page Page size.
	 * @param bool                 $has_more Whether more entries follow (exact for every source).
	 * @param array<string, mixed> $query    Current GET parameters.
	 */
	public function render( string $key, int $page, ?int $total, int $per_page, bool $has_more, array $query ): string {
		if ( null === $total ) {
			return $this->steps( $key, $page, $has_more, $query );
		}
		$pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		if ( $pages <= 1 ) {
			return '';
		}
		$items = array();
		if ( $page > 1 ) {
			$items[] = $this->link( $key, $page - 1, $query, __( 'Previous', 'sw-postlist' ), 'prev' );
		}
		$last = 0;
		foreach ( $this->window( $page, $pages ) as $number ) {
			if ( $number - $last > 1 ) {
				$items[] = '<li><span class="swp-pagination__gap" aria-hidden="true">&hellip;</span></li>';
			}
			$items[] = $number === $page
				? '<li><span class="swp-pagination__link is-current" aria-current="page">' . esc_html( (string) $number ) . '</span></li>'
				/* translators: %d: page number */
				: $this->link( $key, $number, $query, (string) $number, '', sprintf( __( 'Page %d', 'sw-postlist' ), $number ) );
			$last = $number;
		}
		if ( $page < $pages ) {
			$items[] = $this->link( $key, $page + 1, $query, __( 'Next', 'sw-postlist' ), 'next' );
		}
		return $this->wrap( $items, $key );
	}

	/**
	 * Sources without an exact total only get previous and next, and "next" only when a further entry was verified.
	 *
	 * @param array<string, mixed> $query Current GET parameters.
	 */
	private function steps( string $key, int $page, bool $has_more, array $query ): string {
		$items = array();
		if ( $page > 1 ) {
			$items[] = $this->link( $key, $page - 1, $query, __( 'Previous', 'sw-postlist' ), 'prev' );
		}
		if ( $has_more ) {
			$items[] = $this->link( $key, $page + 1, $query, __( 'Next', 'sw-postlist' ), 'next' );
		}
		return array() === $items ? '' : $this->wrap( $items, $key );
	}

	/**
	 * First, last and two pages around the current one.
	 *
	 * @return list<int>
	 */
	private function window( int $page, int $pages ): array {
		$numbers = array_unique( array_merge( array( 1, $pages ), range( max( 1, $page - 2 ), min( $pages, $page + 2 ) ) ) );
		sort( $numbers );
		return $numbers;
	}

	/**
	 * @param array<string, mixed> $query Current GET parameters.
	 */
	private function link( string $key, int $page, array $query, string $label, string $rel, string $aria = '' ): string {
		$href = InstanceRegistry::query_for_page( $query, $key, $page );
		return '<li><a class="swp-pagination__link' . ( '' !== $rel ? ' swp-pagination__link--' . $rel : '' ) . '" href="' . esc_url( $href ) . '"'
			. ( '' !== $rel ? ' rel="' . esc_attr( $rel ) . '"' : '' )
			. ( '' !== $aria ? ' aria-label="' . esc_attr( $aria ) . '"' : '' )
			. '>' . esc_html( $label ) . '</a></li>';
	}

	/**
	 * @param list<string> $items List items.
	 */
	private function wrap( array $items, string $key ): string {
		/* translators: %d: number of the embedding on the page */
		$label = InstanceRegistry::landmark_label( $key, __( 'Pagination', 'sw-postlist' ), __( 'Pagination %d', 'sw-postlist' ) );
		return '<nav class="swp-pagination" aria-label="' . esc_attr( $label ) . '"><ul class="swp-pagination__list">' . implode( '', $items ) . '</ul></nav>';
	}
}
