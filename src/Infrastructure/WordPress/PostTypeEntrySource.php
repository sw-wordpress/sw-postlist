<?php
/**
 * Entry source for one public post type (post, page or a public custom post type).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\EntryResult;
use SW\Postlist\Domain\Source\QueryArgs;
use SW\Postlist\Domain\View\QueryCriteria;

final class PostTypeEntrySource implements EntrySourceInterface {

	public const ID_PREFIX = 'post:';

	private const OPERATORS = array(
		'in'     => 'IN',
		'not_in' => 'NOT IN',
		'and'    => 'AND',
	);

	public function __construct( private readonly string $post_type ) {
	}

	public function id(): string {
		return self::ID_PREFIX . $this->post_type;
	}

	public function label(): string {
		$object = get_post_type_object( $this->post_type );
		return null === $object ? $this->post_type : (string) $object->labels->name;
	}

	public function query_fields(): array {
		return array(
			'taxonomies'      => $this->taxonomies(),
			'orderby'         => QueryCriteria::ORDERBY,
			'visitor_filters' => array( 'terms', 'author', 'search', 'orderby' ),
			'count_mode'      => 'exact',
		);
	}

	public function placeholders(): array {
		return array(
			'entry.id'       => array( 'type' => 'number' ),
			'entry.title'    => array( 'type' => 'text' ),
			'entry.url'      => array( 'type' => 'url' ),
			'entry.date'     => array( 'type' => 'date' ),
			'entry.modified' => array( 'type' => 'date' ),
		);
	}

	public function query( QueryArgs $args ): EntryResult {
		$wp_args = $this->build_args( $args );
		if ( null === $wp_args ) {
			return EntryResult::empty();
		}
		$wp_args = $this->apply_hooks( $wp_args, $args );
		add_filter( 'posts_orderby', array( $this, 'order_by_meta' ), 10, 2 );
		try {
			$query = new \WP_Query( $wp_args );
		} finally {
			remove_filter( 'posts_orderby', array( $this, 'order_by_meta' ), 10 );
		}

		$entries = array();
		foreach ( $query->posts ?? array() as $post ) {
			if ( $post instanceof \WP_Post && $this->is_visible( $post ) ) {
				$entries[] = $this->entry( $post );
			}
		}
		$found = (int) $query->found_posts;
		if ( array() === $entries && ( $args->page > 1 || $args->criteria->offset > 0 ) ) {
			$found = $this->count_matches( $wp_args );
		}
		$total = max( 0, $found - $args->criteria->offset );
		return new EntryResult( $entries, $total, $args->query_offset() + count( $entries ) < $found );
	}

	public function can_view( Entry $entry, ?int $user_id ): bool {
		$post = $entry->native instanceof \WP_Post ? $entry->native : get_post( (int) $entry->id );
		return $post instanceof \WP_Post && $this->is_visible( $post );
	}

	/**
	 * Visible means: right type, published, no password. Identical for guests and signed-in visitors on public routes.
	 */
	private function is_visible( \WP_Post $post ): bool {
		return $post->post_type === $this->post_type
			&& 'publish' === $post->post_status
			&& '' === $post->post_password;
	}

	/**
	 * @return list<string>
	 */
	private function taxonomies(): array {
		return array_values( get_object_taxonomies( $this->post_type, 'names' ) );
	}

	private function entry( \WP_Post $post ): Entry {
		return new Entry(
			$post->ID,
			$this->id(),
			array(
				'id'           => $post->ID,
				'title'        => $post->post_title,
				'url'          => (string) get_permalink( $post ),
				'date_gmt'     => $post->post_date_gmt,
				'modified_gmt' => $post->post_modified_gmt,
				'author_id'    => (int) $post->post_author,
				'post_type'    => $post->post_type,
			),
			$post
		);
	}

	/**
	 * WP_Query arguments for the request, or null when the selection cannot be satisfied (never widened instead).
	 *
	 * @return array<string, mixed>|null
	 */
	private function build_args( QueryArgs $args ): ?array {
		$criteria = $args->criteria;
		$tax      = array();
		$meta     = array();
		$wp_args  = array(
			'post_type'           => $this->post_type,
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => $criteria->per_page,
			'offset'              => $args->query_offset(),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		foreach ( $criteria->taxonomies as $filter ) {
			if ( ! in_array( $filter['taxonomy'], $this->taxonomies(), true ) ) {
				return null;
			}
			$tax[] = $this->tax_clause( $filter['taxonomy'], $filter['terms'], self::OPERATORS[ $filter['operator'] ] ?? 'IN' );
		}
		foreach ( $criteria->meta as $filter ) {
			$clause = array(
				'key'     => $filter['key'],
				'compare' => $filter['compare'],
			);
			if ( ! in_array( $filter['compare'], array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$clause['value'] = $filter['value'];
			}
			$meta[] = $clause;
		}

		$authors = $criteria->authors;
		$visitor = $args->visitor;
		if ( null !== $visitor ) {
			foreach ( $visitor->terms as $taxonomy => $term_ids ) {
				if ( in_array( $taxonomy, $this->taxonomies(), true ) && array() !== $term_ids ) {
					$tax[] = $this->tax_clause( $taxonomy, $term_ids, 'IN' );
				}
			}
			if ( array() !== $visitor->authors ) {
				$authors = array() === $authors ? $visitor->authors : array_values( array_intersect( $authors, $visitor->authors ) );
				if ( array() === $authors ) {
					return null;
				}
			}
			if ( null !== $visitor->search && '' !== trim( $visitor->search ) ) {
				$wp_args['s'] = mb_substr( trim( $visitor->search ), 0, ContextOrigin::MAX_SEARCH_LENGTH );
			}
		}

		$excluded = $criteria->exclude;
		if ( 'none' !== $criteria->context ) {
			$context = $this->context_constraints( $criteria->context, $args->origin );
			if ( null === $context ) {
				return null;
			}
			$tax      = array_merge( $tax, $context['tax'] );
			$excluded = array_merge( $excluded, $context['exclude'] );
			if ( null !== $context['author'] ) {
				$authors = array() === $authors ? array( $context['author'] ) : array_values( array_intersect( $authors, array( $context['author'] ) ) );
				if ( array() === $authors ) {
					return null;
				}
			}
			if ( null !== $context['search'] ) {
				$wp_args['s'] = $context['search'];
			}
		}

		if ( array() !== $authors ) {
			$wp_args['author__in'] = $authors;
		}
		// WP_Query ignores post__not_in as soon as post__in is set: subtract the exclusions from the inclusion list.
		if ( array() !== $criteria->include ) {
			$included = array_values( array_diff( $criteria->include, $excluded ) );
			if ( array() === $included ) {
				return null;
			}
			$wp_args['post__in'] = $included;
		} elseif ( array() !== $excluded ) {
			$wp_args['post__not_in'] = array_values( array_unique( $excluded ) );
		}
		$date_query = $this->date_query( $criteria );
		if ( array() !== $date_query ) {
			$wp_args['date_query'] = array( $date_query );
		}
		if ( array() !== $tax ) {
			$wp_args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- editor defined selection.
		}
		return $this->apply_order( $wp_args, $meta, $criteria, $visitor?->orderby, $visitor?->order );
	}

	/**
	 * @param list<int> $term_ids Term IDs.
	 * @return array<string, mixed>
	 */
	private function tax_clause( string $taxonomy, array $term_ids, string $operator ): array {
		return array(
			'taxonomy'         => $taxonomy,
			'field'            => 'term_id',
			'terms'            => $term_ids,
			'operator'         => $operator,
			'include_children' => 'AND' !== $operator,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function date_query( QueryCriteria $criteria ): array {
		$range = array();
		if ( null !== $criteria->date_after ) {
			$range['after'] = $criteria->date_after . ' 00:00:00';
		}
		if ( null !== $criteria->date_before ) {
			$range['before'] = $criteria->date_before . ' 23:59:59';
		}
		if ( array() !== $range ) {
			$range['inclusive'] = true;
		}
		return $range;
	}

	/**
	 * Context selection derived from public origin data only. Null: required origin missing or not public.
	 *
	 * @return array{tax: list<array<string, mixed>>, exclude: list<int>, author: int|null, search: string|null}|null
	 */
	private function context_constraints( string $mode, ?ContextOrigin $origin ): ?array {
		$none = array(
			'tax'     => array(),
			'exclude' => array(),
			'author'  => null,
			'search'  => null,
		);
		if ( null === $origin ) {
			return null;
		}
		if ( 'search' === $mode ) {
			return ContextOrigin::MODE_SEARCH === $origin->mode && null !== $origin->search && '' !== $origin->search
				? array_merge( $none, array( 'search' => $origin->search ) )
				: null;
		}
		if ( 'archive' === $mode ) {
			return $this->archive_constraints( $origin, $none );
		}
		$post = $this->public_origin_post( $origin );
		if ( null === $post ) {
			return null;
		}
		$none['exclude'] = $post->post_type === $this->post_type ? array( $post->ID ) : array();
		if ( 'same_author' === $mode ) {
			return array_merge( $none, array( 'author' => (int) $post->post_author ) );
		}
		$taxonomy = 'same_category' === $mode ? 'category' : 'post_tag';
		if ( ! in_array( $taxonomy, $this->taxonomies(), true ) ) {
			return null;
		}
		$term_ids = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $term_ids ) || array() === $term_ids ) {
			return null;
		}
		return array_merge( $none, array( 'tax' => array( $this->tax_clause( $taxonomy, array_map( 'intval', $term_ids ), 'IN' ) ) ) );
	}

	/**
	 * @param array{tax: list<array<string, mixed>>, exclude: list<int>, author: int|null, search: string|null} $none Empty constraints.
	 * @return array{tax: list<array<string, mixed>>, exclude: list<int>, author: int|null, search: string|null}|null
	 */
	private function archive_constraints( ContextOrigin $origin, array $none ): ?array {
		if ( ContextOrigin::MODE_AUTHOR === $origin->mode && null !== $origin->id ) {
			return array_merge( $none, array( 'author' => $origin->id ) );
		}
		if ( ContextOrigin::MODE_TERM === $origin->mode && null !== $origin->id && null !== $origin->taxonomy ) {
			$term = get_term( $origin->id, $origin->taxonomy );
			if ( ! $term instanceof \WP_Term || ! is_taxonomy_viewable( $origin->taxonomy ) || ! in_array( $origin->taxonomy, $this->taxonomies(), true ) ) {
				return null;
			}
			return array_merge( $none, array( 'tax' => array( $this->tax_clause( $origin->taxonomy, array( $term->term_id ), 'IN' ) ) ) );
		}
		return null;
	}

	private function public_origin_post( ContextOrigin $origin ): ?\WP_Post {
		if ( ContextOrigin::MODE_POST !== $origin->mode || null === $origin->id ) {
			return null;
		}
		$post = get_post( $origin->id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Sorting with the ID as second key; sorting by meta keeps entries that lack the key.
	 *
	 * @param array<string, mixed>      $wp_args      Arguments so far.
	 * @param list<array<string,mixed>> $meta         Meta filter clauses.
	 * @param string|null               $visitor_sort Visitor requested sort field.
	 * @param string|null               $visitor_dir  Visitor requested direction.
	 * @return array<string, mixed>
	 */
	private function apply_order( array $wp_args, array $meta, QueryCriteria $criteria, ?string $visitor_sort, ?string $visitor_dir ): array {
		$orderby = $criteria->orderby;
		$order   = $criteria->order;
		if ( null !== $visitor_sort && in_array( $visitor_sort, QueryCriteria::ORDERBY, true ) && ( ! str_starts_with( $visitor_sort, 'meta_' ) || null !== $criteria->order_meta_key ) ) {
			$orderby = $visitor_sort;
			$order   = in_array( $visitor_dir, array( 'asc', 'desc' ), true ) ? (string) $visitor_dir : $order;
		}
		$direction = strtoupper( $order );

		if ( str_starts_with( $orderby, 'meta_' ) && null !== $criteria->order_meta_key ) {
			// Sorted by a scoped posts_orderby filter (see order_by_meta()): a meta_query would drop entries that lack the key.
			$wp_args['swp_meta_sort'] = array(
				'key'       => $criteria->order_meta_key,
				'numeric'   => 'meta_num' === $orderby,
				'direction' => $direction,
			);
			$wp_args['orderby']       = array( 'ID' => 'ASC' );
		} else {
			$field              = 'id' === $orderby ? 'ID' : $orderby;
			$wp_args['orderby'] = 'ID' === $field ? array( 'ID' => $direction ) : array(
				$field => $direction,
				'ID'   => 'ASC',
			);
		}
		if ( array() !== $meta ) {
			$wp_args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- editor defined selection.
		}
		return $wp_args;
	}

	/**
	 * Extension hooks narrow the query; afterwards the adapter enforces type, status and password protection.
	 *
	 * @param array<string, mixed> $wp_args Arguments built from the view.
	 * @return array<string, mixed>
	 */
	private function apply_hooks( array $wp_args, QueryArgs $args ): array {
		/**
		 * Filters the WP_Query arguments of an entry source before the query runs.
		 *
		 * Post type, publish status and password protection are enforced again afterwards.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $wp_args  WP_Query arguments.
		 * @param QueryArgs            $args     Request.
		 * @param string               $source_id Entry source ID.
		 */
		$filtered = apply_filters( 'sw_postlist_query_args', $wp_args, $args, $this->id() );
		if ( null !== $args->view_id ) {
			/**
			 * View specific variant of sw_postlist_query_args.
			 *
			 * @since 0.1.0
			 *
			 * @param array<string, mixed> $wp_args WP_Query arguments.
			 * @param QueryArgs            $args    Request.
			 */
			$filtered = apply_filters( 'sw_postlist_query_args_' . $args->view_id, $filtered, $args );
		}
		$result = $this->array_or_default( $filtered, $wp_args );

		$result['post_type']    = $this->post_type;
		$result['post_status']  = 'publish';
		$result['has_password'] = false;
		unset( $result['fields'], $result['p'], $result['page_id'], $result['name'], $result['pagename'], $result['perm'] );
		$result['no_found_rows'] = false;
		return $result;
	}

	/**
	 * Total of matching entries ignoring paging, for requests that landed behind the last page.
	 *
	 * @param array<string, mixed> $wp_args Final arguments.
	 */
	private function count_matches( array $wp_args ): int {
		$wp_args['fields']         = 'ids';
		$wp_args['posts_per_page'] = 1;
		$wp_args['offset']         = 0;
		unset( $wp_args['swp_meta_sort'] );
		return (int) ( new \WP_Query( $wp_args ) )->found_posts;
	}

	/**
	 * Sorts by one meta key through a correlated subquery. It keeps entries without the key (they sort first in
	 * ascending and last in descending order), cannot duplicate rows for repeated keys and stays index friendly,
	 * unlike the OR-ed EXISTS/NOT EXISTS meta_query pattern, which joined the meta table quadratically.
	 *
	 * @param string    $orderby Current ORDER BY clause.
	 * @param \WP_Query $query   Query being built.
	 */
	public function order_by_meta( string $orderby, \WP_Query $query ): string {
		$sort = $query->get( 'swp_meta_sort' );
		if ( ! is_array( $sort ) || ! is_string( $sort['key'] ?? null ) ) {
			return $orderby;
		}
		global $wpdb;
		$direction = 'DESC' === ( $sort['direction'] ?? '' ) ? 'DESC' : 'ASC';
		$aggregate = 'DESC' === $direction ? 'MAX' : 'MIN';
		$value     = empty( $sort['numeric'] ) ? 'm.meta_value' : 'CAST(m.meta_value AS DECIMAL(65,10))';
		// Aggregate, value expression and direction are fixed strings chosen above; the key is a prepared value.
		return $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed strings, see above.
			"(SELECT {$aggregate}({$value}) FROM {$wpdb->postmeta} m WHERE m.post_id = {$wpdb->posts}.ID AND m.meta_key = %s) {$direction}, {$wpdb->posts}.ID ASC",
			$sort['key']
		);
	}

	/**
	 * Filter results come from third parties: anything but an array falls back to the default.
	 *
	 * @param mixed                $value   Filter result.
	 * @param array<string, mixed> $default Value used when the result is not an array.
	 * @return array<string, mixed>
	 */
	private function array_or_default( mixed $value, array $default ): array {
		return is_array( $value ) ? $value : $default;
	}
}
