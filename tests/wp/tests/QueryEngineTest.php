<?php
/**
 * M1 acceptance and query behaviour of the built-in post type source (PLAN.md §4, ADR-05).
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Source\VisitorFilters;

class QueryEngineTest extends SWP_TestCase {

	private int $term;

	/** @var list<int> */
	private array $published = array();

	public function set_up(): void {
		parent::set_up();
		$this->term = self::factory()->category->create( array( 'name' => 'Match' ) );
	}

	/**
	 * Six published, one draft, one private and one password protected post in the category.
	 */
	private function seed_acceptance_content(): void {
		$base = array( 'post_category' => array( $this->term ) );
		foreach ( range( 1, 6 ) as $number ) {
			$this->published[] = self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Published ' . $number ) ) );
		}
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Draft', 'post_status' => 'draft' ) ) );
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Private', 'post_status' => 'private' ) ) );
		self::factory()->post->create( array_merge( $base, array( 'post_title' => 'Protected', 'post_password' => 'secret' ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function acceptance_view( int $per_page = 2, int $offset = 1 ): array {
		return array(
			'query' => array(
				'per_page'   => $per_page,
				'offset'     => $offset,
				'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => array( $this->term ), 'operator' => 'in' ) ),
			),
		);
	}

	/**
	 * @return list<int|string>
	 */
	private function ids( \SW\Postlist\Application\Query\QueryOutcome $outcome ): array {
		$this->assertTrue( $outcome->is_success(), (string) $outcome->error_code );
		return array_map( static fn ( $entry ) => $entry->id, $outcome->result->entries );
	}

	public function test_acceptance_start_offset_page_size_and_total(): void {
		$this->seed_acceptance_content();
		$view = $this->view_document( $this->acceptance_view() );

		$first  = $this->services->query()->run( $view, 1 );
		$second = $this->services->query()->run( $view, 2 );
		$third  = $this->services->query()->run( $view, 3 );

		$this->assertSame( array( $this->published[1], $this->published[2] ), $this->ids( $first ) );
		$this->assertSame( array( $this->published[3], $this->published[4] ), $this->ids( $second ) );
		$this->assertSame( array( $this->published[5] ), $this->ids( $third ) );
		foreach ( array( $first, $second, $third ) as $outcome ) {
			$this->assertSame( 5, $outcome->result->total, 'Visible total after the start offset.' );
		}
		$this->assertTrue( $first->result->has_more );
		$this->assertTrue( $second->result->has_more );
		$this->assertFalse( $third->result->has_more );
	}

	public function test_requested_empty_page_still_reports_the_valid_total(): void {
		$this->seed_acceptance_content();
		$outcome = $this->services->query()->run( $this->view_document( $this->acceptance_view() ), 9 );

		$this->assertSame( array(), $this->ids( $outcome ) );
		$this->assertSame( 5, $outcome->result->total );
		$this->assertFalse( $outcome->result->has_more );
	}

	public function test_offset_beyond_the_matches_gives_total_zero(): void {
		$this->seed_acceptance_content();
		$outcome = $this->services->query()->run( $this->view_document( $this->acceptance_view( 2, 50 ) ), 1 );

		$this->assertSame( array(), $this->ids( $outcome ) );
		$this->assertSame( 0, $outcome->result->total );
	}

	public function test_signed_in_visitors_get_the_same_public_selection(): void {
		$this->seed_acceptance_content();
		$view  = $this->view_document( $this->acceptance_view( 20, 0 ) );
		$guest = $this->ids( $this->services->query()->run( $view, 1 ) );

		$admin_id = $this->admin();
		$admin    = $this->ids( $this->services->query()->run( $view, 1, null, null, null, $admin_id ) );

		$this->assertSame( $guest, $admin );
		$this->assertCount( 6, $admin );
	}

	public function test_visitor_filter_reduces_entries_and_total_consistently(): void {
		$this->seed_acceptance_content();
		$other = self::factory()->category->create( array( 'name' => 'Other' ) );
		wp_set_post_categories( $this->published[2], array( $this->term, $other ) );
		wp_set_post_categories( $this->published[4], array( $this->term, $other ) );

		$view    = $this->view_document( $this->acceptance_view( 2, 1 ) );
		$outcome = $this->services->query()->run( $view, 1, new VisitorFilters( array( 'category' => array( $other ) ) ) );

		$this->assertSame( array( $this->published[4] ), $this->ids( $outcome ), 'Intersection with the fixed criteria, then the start offset.' );
		$this->assertSame( 1, $outcome->result->total );

		$unrelated = self::factory()->category->create( array( 'name' => 'Unrelated' ) );
		$none      = $this->services->query()->run( $view, 1, new VisitorFilters( array( 'category' => array( $unrelated ) ) ) );
		$this->assertSame( array(), $this->ids( $none ) );
		$this->assertSame( 0, $none->result->total );
	}

	public function test_invalid_visitor_values_never_widen_the_selection(): void {
		$this->seed_acceptance_content();
		$view    = $this->view_document( $this->acceptance_view( 20, 0 ) );
		$outcome = $this->services->query()->run(
			$view,
			1,
			new VisitorFilters( array( 'no_such_taxonomy' => array( 5 ), 'post_tag' => array() ), array(), null, 'rand', 'sideways' )
		);

		$this->assertCount( 6, $this->ids( $outcome ), 'Unknown values are ignored; the fixed criteria stay in force.' );
		$this->assertSame( 6, $outcome->result->total );
	}

	public function test_visitor_authors_intersect_with_fixed_authors(): void {
		$author_a = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_b = self::factory()->user->create( array( 'role' => 'author' ) );
		$a_post   = self::factory()->post->create( array( 'post_author' => $author_a ) );
		$b_post   = self::factory()->post->create( array( 'post_author' => $author_b ) );

		$all = $this->view_document( array( 'query' => array( 'authors' => array( $author_a, $author_b ) ) ) );
		$this->assertSame( array( $a_post, $b_post ), $this->ids( $this->services->query()->run( $all ) ) );
		$this->assertSame( array( $b_post ), $this->ids( $this->services->query()->run( $all, 1, new VisitorFilters( array(), array( $author_b ) ) ) ) );

		$only_a = $this->view_document( array( 'query' => array( 'authors' => array( $author_a ) ) ) );
		$empty  = $this->services->query()->run( $only_a, 1, new VisitorFilters( array(), array( $author_b ) ) );
		$this->assertSame( array(), $this->ids( $empty ) );
		$this->assertSame( 0, $empty->result->total );

		$free = $this->view_document();
		$this->assertSame( array( $b_post ), $this->ids( $this->services->query()->run( $free, 1, new VisitorFilters( array(), array( $author_b ) ) ) ) );
	}

	public function test_visitor_search_and_sorting(): void {
		$apple  = self::factory()->post->create( array( 'post_title' => 'Apple pie' ) );
		$banana = self::factory()->post->create( array( 'post_title' => 'Banana split' ) );
		$view   = $this->view_document();

		$found = $this->services->query()->run( $view, 1, new VisitorFilters( array(), array(), 'Banana' ) );
		$this->assertSame( array( $banana ), $this->ids( $found ) );

		$sorted = $this->services->query()->run( $view, 1, new VisitorFilters( array(), array(), null, 'title', 'desc' ) );
		$this->assertSame( array( $banana, $apple ), $this->ids( $sorted ) );

		$ignored = $this->services->query()->run( $view, 1, new VisitorFilters( array(), array(), null, 'meta_num', 'desc' ) );
		$this->assertSame( array( $apple, $banana ), $this->ids( $ignored ), 'Meta sorting is only offered when the view defines a meta key.' );
	}

	public function test_query_hook_narrows_entries_and_total(): void {
		$this->seed_acceptance_content();
		$view   = $this->view_document( $this->acceptance_view( 20, 0 ) );
		$hidden = $this->published[0];
		add_filter(
			'sw_postlist_query_args',
			static function ( array $args ) use ( $hidden ): array {
				$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), array( $hidden ) );
				return $args;
			}
		);

		$outcome = $this->services->query()->run( $view );

		$this->assertNotContains( $hidden, $this->ids( $outcome ) );
		$this->assertSame( 5, $outcome->result->total );
	}

	public function test_view_specific_hook_only_affects_that_view(): void {
		$this->create_posts( 3 );
		$view = $this->view_document();
		add_filter(
			'sw_postlist_query_args_42',
			static function ( array $args ): array {
				$args['posts_per_page'] = 1;
				return $args;
			},
			10,
			1
		);

		$this->assertCount( 3, $this->ids( $this->services->query()->run( $view, 1, null, null, 7 ) ) );
		$this->assertCount( 1, $this->ids( $this->services->query()->run( $view, 1, null, null, 42 ) ) );
	}

	public function test_hooks_cannot_switch_type_status_or_password_filter(): void {
		$page    = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'A page' ) );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$secret  = self::factory()->post->create( array( 'post_password' => 'pw' ) );
		$post    = self::factory()->post->create();
		add_filter(
			'sw_postlist_query_args',
			static function ( array $args ): array {
				$args['post_type']    = array( 'post', 'page' );
				$args['post_status']  = array( 'publish', 'draft', 'private' );
				$args['has_password'] = null;
				$args['fields']       = 'ids';
				$args['p']            = 1;
				return $args;
			}
		);

		$outcome = $this->services->query()->run( $this->view_document() );
		$ids     = $this->ids( $outcome );

		$this->assertSame( array( $post ), $ids );
		foreach ( array( $page, $draft, $private, $secret ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $ids );
		}
		$this->assertSame( 1, $outcome->result->total );
	}

	public function test_non_array_hook_result_falls_back_to_the_view_query(): void {
		$this->create_posts( 2 );
		add_filter( 'sw_postlist_query_args', static fn () => 'broken' );

		$this->assertCount( 2, $this->ids( $this->services->query()->run( $this->view_document() ) ) );
	}

	public function test_entries_hook_may_enrich_but_not_reorder(): void {
		$this->create_posts( 3 );
		add_filter(
			'sw_postlist_entries',
			static fn ( array $entries ): array => array_map( static fn ( $entry ) => $entry->with_data( $entry->data + array( 'badge' => 'new' ) ), $entries )
		);
		$outcome = $this->services->query()->run( $this->view_document() );
		$this->assertSame( 'new', $outcome->result->entries[0]->data['badge'] );
		$this->assertSame( 3, $outcome->result->total );

		remove_all_filters( 'sw_postlist_entries' );
		add_filter( 'sw_postlist_entries', static fn ( array $entries ): array => array_reverse( $entries ) );
		$violation = $this->services->query()->run( $this->view_document() );

		$this->assertFalse( $violation->is_success() );
		$this->assertNull( $violation->result, 'No count is exposed on a contract violation.' );
		$this->assertSame( 'entries_contract_violation', $violation->error_code );
		$logged = \SW\Postlist\Infrastructure\WordPress\WpLogger::entries();
		$this->assertSame( 'entries_contract_violation', end( $logged )['code'] );
	}

	public function test_sorting_by_numeric_and_text_meta_with_id_as_tiebreaker(): void {
		$ten   = self::factory()->post->create( array( 'meta_input' => array( 'rank' => '10' ) ) );
		$nine  = self::factory()->post->create( array( 'meta_input' => array( 'rank' => '9' ) ) );
		$two   = self::factory()->post->create( array( 'meta_input' => array( 'rank' => '2' ) ) );
		$tie   = self::factory()->post->create( array( 'meta_input' => array( 'rank' => '9' ) ) );
		$plain = self::factory()->post->create();

		$numeric = $this->view_document( array( 'query' => array( 'orderby' => 'meta_num', 'order' => 'asc', 'order_meta_key' => 'rank' ) ) );
		$this->assertSame( array( $plain, $two, $nine, $tie, $ten ), $this->ids( $this->services->query()->run( $numeric ) ), 'Entries without the key are kept; equal values fall back to the ID.' );

		$descending = $this->view_document( array( 'query' => array( 'orderby' => 'meta_num', 'order' => 'desc', 'order_meta_key' => 'rank' ) ) );
		$this->assertSame( array( $ten, $nine, $tie, $two, $plain ), $this->ids( $this->services->query()->run( $descending ) ) );

		$text = $this->view_document( array( 'query' => array( 'orderby' => 'meta_text', 'order' => 'asc', 'order_meta_key' => 'rank' ) ) );
		$this->assertSame( array( $plain, $ten, $two, $nine, $tie ), $this->ids( $this->services->query()->run( $text ) ), 'Text sorting compares "10" < "2" < "9".' );
	}

	public function test_meta_sorting_does_not_duplicate_entries_with_repeated_keys(): void {
		$multi = self::factory()->post->create();
		add_post_meta( $multi, 'rank', '5' );
		add_post_meta( $multi, 'rank', '1' );
		$other = self::factory()->post->create( array( 'meta_input' => array( 'rank' => '3' ) ) );

		$asc  = $this->view_document( array( 'query' => array( 'orderby' => 'meta_num', 'order' => 'asc', 'order_meta_key' => 'rank' ) ) );
		$desc = $this->view_document( array( 'query' => array( 'orderby' => 'meta_num', 'order' => 'desc', 'order_meta_key' => 'rank' ) ) );

		$this->assertSame( array( $multi, $other ), $this->ids( $this->services->query()->run( $asc ) ), 'Ascending uses the smallest value.' );
		$this->assertSame( array( $multi, $other ), $this->ids( $this->services->query()->run( $desc ) ), 'Descending uses the largest value.' );
		$this->assertSame( 2, $this->services->query()->run( $asc )->result->total );
	}

	public function test_stable_order_with_equal_dates_uses_the_id(): void {
		$ids = $this->create_posts( 4, array( 'post_date' => '2026-05-01 10:00:00' ) );
		$asc = $this->view_document( array( 'query' => array( 'orderby' => 'date', 'order' => 'asc', 'per_page' => 2 ) ) );

		$first  = $this->ids( $this->services->query()->run( $asc, 1 ) );
		$second = $this->ids( $this->services->query()->run( $asc, 2 ) );

		$this->assertSame( $ids, array_merge( $first, $second ) );
	}

	public function test_fixed_criteria_date_meta_include_exclude_and_authors(): void {
		$old     = self::factory()->post->create( array( 'post_date' => '2025-01-10 12:00:00' ) );
		$mid     = self::factory()->post->create( array( 'post_date' => '2026-03-10 12:00:00', 'meta_input' => array( 'flag' => 'yes' ) ) );
		$new     = self::factory()->post->create( array( 'post_date' => '2026-09-10 12:00:00', 'meta_input' => array( 'flag' => 'no' ) ) );
		$boundry = self::factory()->post->create( array( 'post_date' => '2026-09-30 23:59:30' ) );

		$dated = $this->view_document( array( 'query' => array( 'date' => array( 'after' => '2026-01-01', 'before' => '2026-09-30' ) ) ) );
		$this->assertSame( array( $mid, $new, $boundry ), $this->ids( $this->services->query()->run( $dated ) ), 'Both bounds are inclusive.' );

		$meta = $this->view_document( array( 'query' => array( 'meta' => array( array( 'key' => 'flag', 'value' => 'yes', 'compare' => '=' ) ) ) ) );
		$this->assertSame( array( $mid ), $this->ids( $this->services->query()->run( $meta ) ) );

		$exists = $this->view_document( array( 'query' => array( 'meta' => array( array( 'key' => 'flag', 'value' => '', 'compare' => 'EXISTS' ) ) ) ) );
		$this->assertSame( array( $mid, $new ), $this->ids( $this->services->query()->run( $exists ) ) );

		$include = $this->view_document( array( 'query' => array( 'include' => array( $old, $new ), 'exclude' => array( $new ) ) ) );
		$this->assertSame( array( $old ), $this->ids( $this->services->query()->run( $include ) ) );
	}

	public function test_taxonomy_operators(): void {
		$a    = self::factory()->category->create( array( 'name' => 'A' ) );
		$b    = self::factory()->category->create( array( 'name' => 'B' ) );
		$both = self::factory()->post->create( array( 'post_category' => array( $a, $b ) ) );
		$only = self::factory()->post->create( array( 'post_category' => array( $a ) ) );

		$tax = static fn ( string $operator ): array => array( 'query' => array( 'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => array( $a, $b ), 'operator' => $operator ) ) ) );
		$this->assertSame( array( $both, $only ), $this->ids( $this->services->query()->run( $this->view_document( $tax( 'in' ) ) ) ) );
		$this->assertSame( array( $both ), $this->ids( $this->services->query()->run( $this->view_document( $tax( 'and' ) ) ) ) );
		$not = $this->ids( $this->services->query()->run( $this->view_document( $tax( 'not_in' ) ) ) );
		$this->assertNotContains( $both, $not );
		$this->assertNotContains( $only, $not );
	}

	public function test_unknown_taxonomy_for_the_type_yields_an_empty_exact_result(): void {
		$this->create_posts( 2 );
		$view    = $this->view_document( array( 'query' => array( 'taxonomies' => array( array( 'taxonomy' => 'genre', 'terms' => array( 1 ), 'operator' => 'in' ) ) ) ) );
		$outcome = $this->services->query()->run( $view );

		$this->assertSame( array(), $this->ids( $outcome ) );
		$this->assertSame( 0, $outcome->result->total, 'An unsatisfiable selection is never widened.' );
	}

	public function test_context_same_category_tags_and_author(): void {
		$cat_a   = self::factory()->category->create( array( 'name' => 'CatA' ) );
		$cat_b   = self::factory()->category->create( array( 'name' => 'CatB' ) );
		$author1 = self::factory()->user->create( array( 'role' => 'author' ) );
		$author2 = self::factory()->user->create( array( 'role' => 'author' ) );
		$origin  = self::factory()->post->create( array( 'post_category' => array( $cat_a ), 'tags_input' => array( 'alpha' ), 'post_author' => $author1 ) );
		$same_c  = self::factory()->post->create( array( 'post_category' => array( $cat_a ), 'post_author' => $author2 ) );
		$same_t  = self::factory()->post->create( array( 'post_category' => array( $cat_b ), 'tags_input' => array( 'alpha' ), 'post_author' => $author1 ) );
		self::factory()->post->create( array( 'post_category' => array( $cat_b ), 'post_author' => $author2 ) );

		$origin_post = ContextOrigin::post( $origin );
		$by_category = $this->view_document( array( 'query' => array( 'context' => 'same_category' ) ) );
		$this->assertSame( array( $same_c ), $this->ids( $this->services->query()->run( $by_category, 1, null, $origin_post ) ), 'The origin itself is excluded.' );

		$by_tags = $this->view_document( array( 'query' => array( 'context' => 'same_tags' ) ) );
		$this->assertSame( array( $same_t ), $this->ids( $this->services->query()->run( $by_tags, 1, null, $origin_post ) ) );

		$by_author = $this->view_document( array( 'query' => array( 'context' => 'same_author' ) ) );
		$this->assertSame( array( $same_t ), $this->ids( $this->services->query()->run( $by_author, 1, null, $origin_post ) ) );
	}

	public function test_context_archive_and_search(): void {
		$cat    = self::factory()->category->create( array( 'name' => 'Archive' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$in_cat = self::factory()->post->create( array( 'post_category' => array( $cat ), 'post_title' => 'Findable thing' ) );
		$by_a   = self::factory()->post->create( array( 'post_author' => $author, 'post_title' => 'Other' ) );

		$archive = $this->view_document( array( 'query' => array( 'context' => 'archive' ) ) );
		$this->assertSame( array( $in_cat ), $this->ids( $this->services->query()->run( $archive, 1, null, ContextOrigin::term( 'category', $cat ) ) ) );
		$this->assertSame( array( $by_a ), $this->ids( $this->services->query()->run( $archive, 1, null, ContextOrigin::author( $author ) ) ) );

		$search = $this->view_document( array( 'query' => array( 'context' => 'search' ) ) );
		$this->assertSame( array( $in_cat ), $this->ids( $this->services->query()->run( $search, 1, null, ContextOrigin::search( 'Findable' ) ) ) );
	}

	public function test_missing_or_non_public_origin_ends_the_context_selection(): void {
		$this->create_posts( 3 );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$secret  = self::factory()->post->create( array( 'post_password' => 'pw' ) );
		$view    = $this->view_document( array( 'query' => array( 'context' => 'same_category' ) ) );

		foreach ( array( null, ContextOrigin::post( $draft ), ContextOrigin::post( $private ), ContextOrigin::post( $secret ), ContextOrigin::post( 999999 ), ContextOrigin::search( 'x' ) ) as $origin ) {
			$outcome = $this->services->query()->run( $view, 1, null, $origin );
			$this->assertSame( array(), $this->ids( $outcome ) );
			$this->assertSame( 0, $outcome->result->total );
		}
		$archive = $this->view_document( array( 'query' => array( 'context' => 'archive' ) ) );
		$this->assertSame( array(), $this->ids( $this->services->query()->run( $archive, 1, null, ContextOrigin::term( 'category', 999999 ) ) ) );
		$this->assertSame( array(), $this->ids( $this->services->query()->run( $archive, 1, null, ContextOrigin::post( 1 ) ) ) );
		$search = $this->view_document( array( 'query' => array( 'context' => 'search' ) ) );
		$this->assertSame( array(), $this->ids( $this->services->query()->run( $search, 1, null, ContextOrigin::search( '   ' ) ) ) );
	}

	public function test_origin_is_read_from_the_main_query(): void {
		$cat     = self::factory()->category->create( array( 'name' => 'Main' ) );
		$post_id = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$reader  = new \SW\Postlist\Infrastructure\WordPress\MainQueryOriginReader();

		$this->go_to( get_permalink( $post_id ) );
		$origin = $reader->read();
		$this->assertSame( ContextOrigin::MODE_POST, $origin->mode );
		$this->assertSame( $post_id, $origin->id );

		$this->go_to( get_category_link( $cat ) );
		$origin = $reader->read();
		$this->assertSame( array( ContextOrigin::MODE_TERM, 'category', $cat ), array( $origin->mode, $origin->taxonomy, $origin->id ) );

		$this->go_to( get_author_posts_url( $author ) );
		$origin = $reader->read();
		$this->assertSame( array( ContextOrigin::MODE_AUTHOR, $author ), array( $origin->mode, $origin->id ) );

		$this->go_to( home_url( '/?s=needle' ) );
		$origin = $reader->read();
		$this->assertSame( array( ContextOrigin::MODE_SEARCH, 'needle' ), array( $origin->mode, $origin->search ) );

		$this->go_to( home_url( '/?s=' ) );
		$this->assertNull( $reader->read() );

		$this->go_to( home_url( '/' ) );
		$this->assertNull( $reader->read() );
		$this->assertNull( $reader->read( new WP_Query() ) );
	}

	public function test_pages_and_public_custom_post_types_are_sources(): void {
		register_post_type( 'event', array( 'public' => true, 'label' => 'Events', 'taxonomies' => array( 'category' ) ) );
		register_post_type( 'secret_type', array( 'public' => false, 'label' => 'Secret' ) );
		\SW\Postlist\Services::reset();
		$sources = \SW\Postlist\Services::instance()->sources()->all();

		$this->assertArrayHasKey( 'post:post', $sources );
		$this->assertArrayHasKey( 'post:page', $sources );
		$this->assertArrayHasKey( 'post:event', $sources );
		$this->assertArrayNotHasKey( 'post:attachment', $sources );
		$this->assertArrayNotHasKey( 'post:secret_type', $sources );
		$this->assertSame( 'Events', $sources['post:event']->label() );
		$this->assertContains( 'category', $sources['post:event']->query_fields()['taxonomies'] );
		$this->assertSame( 'exact', $sources['post:page']->query_fields()['count_mode'] );
		$this->assertArrayHasKey( 'entry.title', $sources['post:page']->placeholders() );

		$event = self::factory()->post->create( array( 'post_type' => 'event', 'post_title' => 'Concert' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'About' ) );
		$view = $this->services()->view_validator()->validate( array( 'schema_version' => 1, 'title' => 'E', 'source' => 'post:event', 'query' => array(), 'layout' => array( 'type' => 'list' ), 'pagination' => array( 'mode' => 'none' ), 'templates' => array() ) )->value();
		$this->assertSame( array( $event ), $this->ids( \SW\Postlist\Services::instance()->query()->run( $view ) ) );
	}

	public function test_third_party_sources_are_validated_and_diagnosed(): void {
		add_filter(
			'sw_postlist_entry_sources',
			static fn ( array $sources ): array => array_merge(
				$sources,
				array(
					new \SW\Postlist\Tests\Support\FakeSource( 'ext:items' ),
					new \SW\Postlist\Tests\Support\FakeSource( 'post:post' ),
					'garbage',
				)
			)
		);
		\SW\Postlist\Services::reset();
		$services = \SW\Postlist\Services::instance();

		$this->assertArrayHasKey( 'ext:items', $services->sources()->all() );
		$codes = array_column( $services->diagnostics(), 'code' );
		$this->assertContains( 'source_id_duplicate', $codes );
		$this->assertContains( 'source_interface', $codes );

		remove_all_filters( 'sw_postlist_entry_sources' );
		add_filter( 'sw_postlist_entry_sources', static fn () => 'not an array' );
		\SW\Postlist\Services::reset();
		$this->assertSame( 'sources_not_array', \SW\Postlist\Services::instance()->diagnostics()[0]['code'] );
	}

	private function services(): \SW\Postlist\Services {
		return \SW\Postlist\Services::instance();
	}
}
