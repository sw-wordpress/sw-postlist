<?php
/**
 * WordPress side of the template engine: placeholders, resolvers, escaping, content sanitizing and the event driven
 * invalidation of compiled templates (PLAN.md §5).
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\Engine\ContentSanitizer;
use SW\Postlist\Infrastructure\WordPress\Engine\MetaAllowlist;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateCompilation;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateEngineFactory;
use SW\Postlist\Tests\Support\Engine\ArrayAstCache;

class TemplateEngineWpTest extends SWP_TestCase {

	private ArrayAstCache $cache;

	private TemplateCompilation $compilation;

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'event', array( 'public' => true, 'label' => 'Events', 'taxonomies' => array( 'category' ) ) );
		\SW\Postlist\Services::reset();
		$this->services    = \SW\Postlist\Services::instance();
		$this->cache       = new ArrayAstCache();
		$factory           = new TemplateEngineFactory( $this->services->placeholders(), new MetaAllowlist(), $this->services->generations(), new ContentSanitizer(), $this->cache );
		$this->compilation = new TemplateCompilation( $this->services->templates(), $factory );
		$this->services->ast_invalidator()->register();
	}

	private function source( string $id = 'post:post' ) {
		$source = $this->services->sources()->get( $id );
		$this->assertNotNull( $source );
		return $source;
	}

	private function template( string $html, string $title = 'T', TemplateRole $role = TemplateRole::Entry ): int {
		return $this->services->templates()->save( null, $this->template_document( $html, $role, $title ) );
	}

	private function render_entry( int $template_id, int $post_id, string $source_id = 'post:post' ): string {
		$source  = $this->source( $source_id );
		$result  = $this->compilation->compile( $template_id, $source );
		$this->assertTrue( $result->is_valid(), (string) wp_json_encode( array_map( static fn ( $error ) => $error->to_array(), $result->errors ) ) );
		$factory = new TemplateEngineFactory( $this->services->placeholders(), new MetaAllowlist(), $this->services->generations() );
		$entry   = new Entry( $post_id, $source_id, array(), get_post( $post_id ) );
		return $factory->renderer()->render( $result->template, $factory->entry_resolver( $entry, $source ) );
	}

	public function test_standard_placeholders_have_type_description_and_roles(): void {
		$schema = $this->services->placeholders()->for_source( $this->source() );

		$title = $schema->find( 'entry.title', 'entry' );
		$this->assertSame( 'text', $title->type->value );
		$this->assertNotSame( '', $title->description );
		$this->assertNotSame( '', $title->example );
		$this->assertNull( $schema->find( 'entry.title', 'container' ), 'Entry placeholders need an entry.' );
		$this->assertNotNull( $schema->find( 'view.entries', 'container' ) );
		$this->assertNull( $schema->find( 'view.entries', 'entry' ) );
		$this->assertNotNull( $schema->find( 'view.title', 'entry' ), 'view.title is available in both roles.' );
		$this->assertSame( 'list', $schema->find( 'entry.terms.category', 'entry' )->type->value );
		$this->assertNotNull( $schema->find( 'entry.terms.post_tag', 'entry' ) );
		$this->assertNull( $schema->find( 'entry.terms.genre', 'entry' ), 'Only taxonomies of the source are offered.' );
		$names = array_map( static fn ( $definition ) => $definition->name, $schema->all( 'entry' ) );
		foreach ( array( 'entry.id', 'entry.title', 'entry.excerpt', 'entry.content', 'entry.date', 'entry.modified', 'entry.url', 'entry.image', 'entry.author.name', 'entry.author.url' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	public function test_meta_placeholders_are_limited_to_allowed_keys(): void {
		update_option( 'sw_postlist_settings', array( 'meta_keys' => array( 'post:post' => array( 'subtitle', '_secret', 'bad key!', 'subtitle' ) ) ) );
		$post_id = self::factory()->post->create( array( 'meta_input' => array( 'subtitle' => 'Sub <b>title</b>', 'private_note' => 'hidden', '_secret' => 'nope', 'serialized' => array( 'a' ) ) ) );

		$schema = $this->services->placeholders()->for_source( $this->source() );
		$this->assertNotNull( $schema->find( 'entry.meta.subtitle', 'entry' ) );
		$this->assertNull( $schema->find( 'entry.meta.private_note', 'entry' ) );
		$this->assertNull( $schema->find( 'entry.meta._secret', 'entry' ) );
		$this->assertSame( array( 'subtitle' ), ( new MetaAllowlist() )->keys( 'post:post' ) );

		$template = $this->template( '<p>{{entry.meta.subtitle}}</p>' );
		$this->assertSame( '<p>Sub &lt;b&gt;title&lt;/b&gt;</p>', $this->render_entry( $template, $post_id ) );

		$unknown = $this->compilation->validate( $this->template_document( '{{entry.meta.private_note}}' ), $this->source() );
		$this->assertSame( array( 'unknown_placeholder' ), $unknown->error_codes() );

		$factory  = new TemplateEngineFactory( $this->services->placeholders(), new MetaAllowlist(), $this->services->generations() );
		$resolver = $factory->entry_resolver( new Entry( $post_id, 'post:post', array(), get_post( $post_id ) ), $this->source() );
		$this->assertNull( $resolver->resolve( 'entry.meta.private_note' ), 'The resolver enforces the allowlist itself.' );
		$this->assertNull( $resolver->resolve( 'entry.meta._secret' ) );
	}

	public function test_meta_allowlist_can_be_extended_by_filter(): void {
		add_filter( 'sw_postlist_allowed_meta_keys', static fn ( array $keys, string $source_id ): array => 'post:page' === $source_id ? array_merge( $keys, array( 'teaser', '../bad' ) ) : $keys, 10, 2 );

		$this->assertSame( array( 'teaser' ), ( new MetaAllowlist() )->keys( 'post:page' ) );
		$this->assertSame( array(), ( new MetaAllowlist() )->keys( 'post:post' ) );
		$this->assertSame( array(), MetaAllowlist::sanitize( 'not an array' ) );
	}

	public function test_entry_values_are_resolved_and_escaped_for_the_output_context(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Al <b>Ex</b> & Co' ) );
		$cat     = self::factory()->category->create( array( 'name' => 'News & "Views"' ) );
		$this->admin();
		$post_id = self::factory()->post->create(
			array(
				'post_title'    => 'Tom & Jerry <script>alert(1)</script> "quoted"',
				'post_excerpt'  => '<p>An <b>excerpt</b> &amp; more</p>',
				'post_author'   => $author,
				'post_category' => array( $cat ),
				'post_date'     => '2026-03-09 14:05:00',
			)
		);
		$template = $this->template( '<article title="{{entry.title}}"><h2>{{entry.title}}</h2><p>{{entry.excerpt}}</p><a href="{{entry.url}}">{{entry.author.name}}</a>{{entry.terms.category|link}} <time>{{entry.date|date:"d.m.Y"}}</time></article>' );

		$html = $this->render_entry( $template, $post_id );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '<article title="Tom &amp; Jerry', $html, 'Entities of the title are escaped once.' );
		$this->assertStringContainsString( '<h2>Tom &amp; Jerry', $html );
		$this->assertStringNotContainsString( 'alert(1)</script>', $html );
		$this->assertStringContainsString( '<p>An excerpt &amp; more</p>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $post_id ) ) . '"', $html );
		$this->assertStringContainsString( '>Al Ex &amp; Co</a>', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_category_link( $cat ) ) . '">News &amp; &quot;Views&quot;</a>', $html );
		$this->assertStringContainsString( '<time>09.03.2026</time>', $html );
	}

	public function test_dates_use_the_site_time_zone(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$post_id  = self::factory()->post->create( array( 'post_date_gmt' => '2026-03-09 20:30:00', 'post_date' => '2026-03-10 05:30:00' ) );
		$template = $this->template( '{{entry.date|date:"Y-m-d H:i"}}' );

		$this->assertSame( '2026-03-10 05:30', $this->render_entry( $template, $post_id ) );
		update_option( 'timezone_string', 'America/Los_Angeles' );
		$this->assertSame( '2026-03-09 13:30', $this->render_entry( $template, $post_id ) );
	}

	public function test_site_date_format_and_number_formatting_follow_wordpress_settings(): void {
		update_option( 'date_format', 'j. F Y' );
		$post_id = self::factory()->post->create( array( 'post_date' => '2026-03-09 10:00:00' ) );
		$this->assertSame( '9. March 2026', $this->render_entry( $this->template( '{{entry.date}}' ), $post_id ) );
		$this->assertSame( '9. March 2026', $this->render_entry( $this->template( '{{entry.date|date}}' ), $post_id ) );

		$locale = new \SW\Postlist\Infrastructure\WordPress\Engine\WpLocale();
		$this->assertSame( '1,234.57', $locale->format_number( 1234.567, 2 ) );
		$this->assertStringContainsString( '1,234.50', $locale->format_currency( 1234.5, 'EUR' ) );
		$now = new DateTimeImmutable( '2026-11-18 12:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertSame( '3 days ago', $locale->relative_date( new DateTimeImmutable( '2026-11-15 12:00:00', new DateTimeZone( 'UTC' ) ), $now ) );
		$this->assertSame( 'in 3 days', $locale->relative_date( new DateTimeImmutable( '2026-11-21 12:00:00', new DateTimeZone( 'UTC' ) ), $now ) );
	}

	public function test_featured_image_renders_as_url_and_as_responsive_image(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( dirname( __DIR__, 2 ) . '/Fixtures/pixel.png', $post_id );
		if ( 0 === $attachment_id ) {
			$this->markTestSkipped( 'Image upload is not available.' );
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Alt "text"' );
		set_post_thumbnail( $post_id, $attachment_id );

		$url_html = $this->render_entry( $this->template( '<img src="{{entry.image}}" alt="">' ), $post_id );
		$this->assertMatchesRegularExpression( '#^<img src="http[^"]+pixel[^"]*\.png" alt="">$#', $url_html );

		$image_html = $this->render_entry( $this->template( '<figure>{{entry.image|image:"full"}}</figure>' ), $post_id );
		$this->assertStringContainsString( '<img ', $image_html );
		$this->assertStringContainsString( 'alt="Alt &quot;text&quot;"', $image_html );
		$this->assertStringContainsString( 'loading="lazy"', $image_html );

		$no_image = self::factory()->post->create();
		$this->assertSame( '<figure></figure>', $this->render_entry( $this->template( '<figure>{{entry.image|image:"full"}}</figure>' ), $no_image ) );
		$unknown_size = $this->compilation->validate( $this->template_document( '{{entry.image|image:"nonexistent-size"}}' ), $this->source() );
		$this->assertSame( array( 'invalid_parameter' ), $unknown_size->error_codes() );
	}

	public function test_content_is_sanitized_without_executing_shortcodes_or_dynamic_blocks(): void {
		$executed = 0;
		add_shortcode(
			'swp_probe',
			static function () use ( &$executed ): string {
				++$executed;
				return '<b>SHORTCODE-OUTPUT</b>';
			}
		);
		register_block_type(
			'swp-test/dynamic',
			array(
				'render_callback' => static function () use ( &$executed ): string {
					++$executed;
					return '<p>BLOCK-OUTPUT</p>';
				},
			)
		);
		$content = "<!-- wp:paragraph -->\n<p>Static <em>text</em> <script>alert(1)</script><a href=\"javascript:alert(1)\" onclick=\"x\">link</a></p>\n<!-- /wp:paragraph -->\n"
			. "<!-- wp:swp-test/dynamic /-->\n"
			. "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph --><!-- wp:swp-test/dynamic /--></div>\n<!-- /wp:group -->\n"
			. '[swp_probe] [sw_postlist id="1"]';
		$post_id = self::factory()->post->create( array( 'post_content' => $content ) );

		$html = $this->render_entry( $this->template( '<div>{{entry.content}}</div>' ), $post_id );

		$this->assertSame( 0, $executed, 'Neither shortcodes nor dynamic blocks are executed.' );
		$this->assertStringContainsString( '<em>text</em>', $html );
		$this->assertStringContainsString( 'Inner', $html );
		foreach ( array( 'BLOCK-OUTPUT', 'SHORTCODE-OUTPUT', '[swp_probe]', 'sw_postlist', '<script', 'javascript:', 'onclick', 'wp:swp-test' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $html );
		}
		unregister_block_type( 'swp-test/dynamic' );
	}

	public function test_content_of_a_post_that_embeds_the_postlist_itself_is_bounded(): void {
		add_shortcode( 'sw_postlist', static fn (): string => 'RECURSION' );
		$post_id = self::factory()->post->create( array( 'post_content' => '[sw_postlist id="1"] <!-- wp:sw-postlist/list {"viewId":1} /--> text' . str_repeat( ' x', 200000 ) ) );

		$html = $this->render_entry( $this->template( '{{entry.content}}' ), $post_id );

		$this->assertStringNotContainsString( 'RECURSION', $html );
		$this->assertLessThan( ContentSanitizer::MAX_BYTES * 2, strlen( $html ) );
	}

	public function test_formatters_and_placeholders_can_be_extended_and_faulty_registrations_are_diagnosed(): void {
		add_filter(
			'sw_postlist_formatters',
			static fn ( array $formatters ): array => array_merge(
				$formatters,
				array(
					new \SW\Postlist\TemplateEngine\Formatter\CallbackFormatter( 'shout', new \SW\Postlist\TemplateEngine\FormatterSignature( array( \SW\Postlist\TemplateEngine\ValueType::Text ), \SW\Postlist\TemplateEngine\ValueType::Text ), static fn ( mixed $value ): string => strtoupper( (string) $value ) . '!' ),
					'garbage',
				)
			)
		);
		add_filter(
			'sw_postlist_placeholders',
			static fn ( array $placeholders, string $source_id ): array => array_merge(
				$placeholders,
				array(
					'entry.tagline' => array( 'type' => 'text', 'description' => 'Tagline', 'example' => 'x', 'roles' => array( 'entry', 'nonsense' ) ),
					'bad name'      => array( 'type' => 'text' ),
					'entry.wrong'   => array( 'type' => 'unknown-type' ),
				)
			),
			10,
			2
		);
		\SW\Postlist\Services::reset();
		$services = \SW\Postlist\Services::instance();
		$schema   = $services->placeholders()->for_source( $services->sources()->get( 'post:post' ) );

		$this->assertNotNull( $schema->find( 'entry.tagline', 'entry' ) );
		$this->assertNull( $schema->find( 'entry.tagline', 'container' ) );
		$this->assertNull( $schema->find( 'entry.wrong', 'entry' ) );
		$this->assertNotNull( $services->engine()->formatters()->get( 'shout' ) );
		$codes = array_column( $services->diagnostics(), 'code' );
		$this->assertContains( 'formatter_interface', $codes );
		$this->assertSame( 2, count( array_filter( $codes, static fn ( string $code ): bool => 'placeholder_invalid' === $code ) ) );

		remove_all_filters( 'sw_postlist_placeholders' );
		add_filter( 'sw_postlist_placeholders', static fn () => 'broken' );
		\SW\Postlist\Services::reset();
		$services = \SW\Postlist\Services::instance();
		$services->placeholders()->for_source( $services->sources()->get( 'post:post' ) );
		$this->assertContains( 'placeholders_not_array', array_column( $services->diagnostics(), 'code' ) );
	}

	/**
	 * @return array{0: int, 1: int} Template IDs for the post and the event source.
	 */
	private function warm_cache(): array {
		$post_template  = $this->template( '<p>{{entry.title}}</p>', 'P' );
		$event_template = $this->template( '<i>{{entry.title}}</i>', 'E' );
		$this->assertTrue( $this->compilation->compile( $post_template, $this->source( 'post:post' ) )->is_valid() );
		$this->assertTrue( $this->compilation->compile( $event_template, $this->source( 'post:event' ) )->is_valid() );
		$this->assertSame( 2, $this->cache->writes );
		return array( $post_template, $event_template );
	}

	private function recompiles( int $template_id, string $source_id ): bool {
		$before = $this->cache->writes;
		$this->compilation->compile( $template_id, $this->source( $source_id ) );
		return $this->cache->writes > $before;
	}

	public function test_updating_a_post_recompiles_only_the_affected_source(): void {
		list( $post_template, $event_template ) = $this->warm_cache();
		$post_id  = self::factory()->post->create( array( 'post_title' => 'Old' ) );
		$event_id = self::factory()->post->create( array( 'post_type' => 'event', 'post_title' => 'Old event' ) );
		$this->recompiles( $post_template, 'post:post' );
		$this->recompiles( $event_template, 'post:event' );

		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ), 'Warm again: cache hit.' );
		$this->assertFalse( $this->recompiles( $event_template, 'post:event' ) );

		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'New' ) );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ), 'The post source recompiles lazily.' );
		$this->assertFalse( $this->recompiles( $event_template, 'post:event' ), 'The event source is untouched.' );
		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ) );

		wp_update_post( array( 'ID' => $event_id, 'post_title' => 'New event' ) );
		$this->assertTrue( $this->recompiles( $event_template, 'post:event' ), 'A custom post type works the same way.' );
		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ) );
	}

	public function test_unchanged_registration_is_a_cache_hit_and_a_changed_schema_is_not(): void {
		list( $post_template ) = $this->warm_cache();

		\SW\Postlist\Services::reset();
		$this->services = \SW\Postlist\Services::instance();
		$factory        = new TemplateEngineFactory( $this->services->placeholders(), new MetaAllowlist(), $this->services->generations(), new ContentSanitizer(), $this->cache );
		$compilation    = new TemplateCompilation( $this->services->templates(), $factory );
		$before         = $this->cache->writes;
		$compilation->compile( $post_template, $this->services->sources()->get( 'post:post' ) );
		$this->assertSame( $before, $this->cache->writes, 'A fresh start with the same registration reuses the compiled template.' );

		register_taxonomy( 'genre', 'post', array( 'public' => true ) );
		\SW\Postlist\Services::reset();
		$this->services = \SW\Postlist\Services::instance();
		$factory        = new TemplateEngineFactory( $this->services->placeholders(), new MetaAllowlist(), $this->services->generations(), new ContentSanitizer(), $this->cache );
		$compilation    = new TemplateCompilation( $this->services->templates(), $factory );
		$compilation->compile( $post_template, $this->services->sources()->get( 'post:post' ) );
		$this->assertSame( $before + 1, $this->cache->writes, 'A new taxonomy changes the schema fingerprint.' );
	}

	public function test_meta_and_term_changes_invalidate_only_when_relevant(): void {
		update_option( 'sw_postlist_settings', array( 'meta_keys' => array( 'post:post' => array( 'subtitle' ) ) ) );
		list( $post_template ) = $this->warm_cache();
		$post_id = self::factory()->post->create();
		$this->recompiles( $post_template, 'post:post' );

		update_post_meta( $post_id, 'unlisted', 'x' );
		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ), 'A meta key outside the allowlist is irrelevant.' );
		add_post_meta( $post_id, 'subtitle', 'x' );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ) );
		update_post_meta( $post_id, 'subtitle', 'y' );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ) );
		delete_post_meta( $post_id, 'subtitle' );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ) );

		$cat = self::factory()->category->create();
		wp_set_post_categories( $post_id, array( $cat ) );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ), 'A term assignment through the API invalidates.' );
		wp_remove_object_terms( $post_id, $cat, 'category' );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ), 'Removing the assignment too.' );
		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ) );
	}

	public function test_deletion_type_change_revisions_and_autosaves(): void {
		list( $post_template, $event_template ) = $this->warm_cache();
		$post_id = self::factory()->post->create();
		$this->recompiles( $post_template, 'post:post' );
		$this->recompiles( $event_template, 'post:event' );

		wp_update_post( array( 'ID' => $post_id, 'post_type' => 'event' ) );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ), 'The former source is invalidated on a type change.' );
		$this->assertTrue( $this->recompiles( $event_template, 'post:event' ), 'The new source too.' );

		wp_delete_post( $post_id, true );
		$this->assertTrue( $this->recompiles( $event_template, 'post:event' ), 'Final deletion invalidates the source.' );

		$page = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->recompiles( $post_template, 'post:post' );
		wp_save_post_revision( $page );
		wp_create_post_autosave( array( 'post_ID' => $page, 'post_type' => 'post', 'post_title' => 'autosave', 'post_content' => 'x', 'post_excerpt' => '' ) );
		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ), 'Revisions and autosaves are skipped.' );
	}

	public function test_content_invalidation_can_be_disabled_and_titles_stay_current(): void {
		list( $post_template ) = $this->warm_cache();
		$post_id = self::factory()->post->create( array( 'post_title' => 'Old title' ) );
		$this->recompiles( $post_template, 'post:post' );
		add_filter(
			'sw_postlist_ast_invalidation_scope',
			static fn ( bool $invalidate, string $reason, string $source_id ): bool => 'post:post' === $source_id ? false : $invalidate,
			10,
			3
		);

		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Brand new title' ) );

		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ), 'The filter switched the content invalidation off.' );
		$this->assertSame( '<p>Brand new title</p>', $this->render_entry( $post_template, $post_id ), 'The AST holds no content: the current title appears on a cache hit.' );
	}

	public function test_template_publication_integrations_and_global_invalidation(): void {
		list( $post_template, $event_template ) = $this->warm_cache();
		$this->services->templates()->save( $post_template, $this->template_document( '<p class="v2">{{entry.title}}</p>', TemplateRole::Entry, 'P' ) );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ), 'A published template compiles again.' );
		$this->assertFalse( $this->recompiles( $event_template, 'post:event' ), 'Other templates are not affected.' );

		do_action( 'sw_postlist_invalidate_template_ast', 'my-sync', 'source', 'post:event' );
		$this->assertTrue( $this->recompiles( $event_template, 'post:event' ) );
		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ) );

		do_action( 'sw_postlist_invalidate_template_ast', 'my-sync', 'template', $post_template );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ) );

		do_action( 'sw_postlist_invalidate_template_ast', 'my-sync', 'all' );
		$this->assertTrue( $this->recompiles( $post_template, 'post:post' ) );
		$this->assertTrue( $this->recompiles( $event_template, 'post:event' ) );

		do_action( 'sw_postlist_invalidate_template_ast', 'noop', 'unknown-scope', 1 );
		do_action( 'sw_postlist_invalidate_template_ast', 'noop', 'source', '' );
		$this->assertFalse( $this->recompiles( $post_template, 'post:post' ) );
	}

	public function test_a_removed_formatter_is_a_validation_error_and_damaged_cache_entries_recompile(): void {
		$template = $this->template( '<p>{{entry.title|upper}}</p>' );
		$this->assertTrue( $this->compilation->compile( $template, $this->source() )->is_valid() );

		remove_all_filters( 'sw_postlist_formatters' );
		add_filter( 'sw_postlist_formatters', static fn ( array $formatters ): array => array_filter( $formatters, static fn ( $formatter ): bool => 'upper' !== $formatter->name() ) );
		\SW\Postlist\Services::reset();
		$this->services = \SW\Postlist\Services::instance();
		$factory        = new TemplateEngineFactory( $this->services->placeholders(), new MetaAllowlist(), $this->services->generations(), new ContentSanitizer(), $this->cache );
		$compilation    = new TemplateCompilation( $this->services->templates(), $factory );
		$result         = $compilation->compile( $template, $this->services->sources()->get( 'post:post' ) );
		$this->assertFalse( $result->is_valid() );
		$this->assertSame( array( 'unknown_formatter' ), $result->error_codes() );

		remove_all_filters( 'sw_postlist_formatters' );
		\SW\Postlist\Services::reset();
		$this->services = \SW\Postlist\Services::instance();
		$factory        = new TemplateEngineFactory( $this->services->placeholders(), new MetaAllowlist(), $this->services->generations(), new ContentSanitizer(), $this->cache );
		$compilation    = new TemplateCompilation( $this->services->templates(), $factory );
		$key            = array_key_first( $this->cache->entries );
		$this->cache->entries[ $key ] = array( 'engine' => '1', 'ast' => 'damaged', 'css' => '', 'scope' => 'x' );
		$writes = $this->cache->writes;
		$this->assertTrue( $compilation->compile( $template, $this->services->sources()->get( 'post:post' ) )->is_valid() );
		$this->assertSame( $writes + 1, $this->cache->writes );
	}

	public function test_missing_templates_and_transient_cache_roundtrip(): void {
		$missing = $this->compilation->compile( 999999, $this->source() );
		$this->assertSame( array( 'template_missing' ), $missing->error_codes() );

		$cache = new \SW\Postlist\Infrastructure\WordPress\Engine\WpAstCache();
		$this->assertNull( $cache->get( 'swp_ast_unknown' ) );
		$cache->set( 'swp_ast_unknown', array( 'a' => 1 ) );
		$this->assertSame( array( 'a' => 1 ), $cache->get( 'swp_ast_unknown' ) );
		set_transient( 'sw_postlist_ast_' . substr( hash( 'sha256', 'swp_ast_junk' ), 0, 40 ), 'not an array', 60 );
		$this->assertNull( $cache->get( 'swp_ast_junk' ) );
	}

	public function test_default_services_use_the_transient_cache_and_clean_up_on_uninstall(): void {
		$services = \SW\Postlist\Services::instance();
		$template = $this->template( '<p>{{entry.title}}</p>' );
		$source   = $services->sources()->get( 'post:post' );
		$services->template_compilation()->compile( $template, $source );
		$this->assertNotEmpty( $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient\\_sw\\_postlist\\_ast\\_%'" ) );

		( new \SW\Postlist\Infrastructure\WordPress\DataCleaner() )->clean();

		$this->assertEmpty( $GLOBALS['wpdb']->get_col( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '%sw\\_postlist\\_ast%'" ) );
	}

	public function test_escaper_checks_urls_and_colors(): void {
		$escaper = new \SW\Postlist\Infrastructure\WordPress\Engine\WpEscaper();

		$this->assertSame( 'https://example.com/a?b=1&c=2', $escaper->url( 'https://example.com/a?b=1&c=2' ), 'The query stays intact; the attribute escaper encodes the ampersand when it is output.' );
		$this->assertNull( $escaper->url( 'javascript:alert(1)' ) );
		$this->assertNull( $escaper->url( '//evil.test/x' ) );
		$this->assertNull( $escaper->url( 'ftp://example.com/file' ) );
		$this->assertSame( 'mailto:a@example.com', $escaper->url( 'mailto:a@example.com' ) );
		$this->assertSame( '/relative/path', $escaper->url( '/relative/path' ) );
		$this->assertSame( '#ff0000', $escaper->color( '#FF0000' ) );
		$this->assertNull( $escaper->color( 'red' ) );
		$this->assertSame( '&lt;b&gt; &amp; &quot;x&quot;', $escaper->text( '<b> & "x"' ) );
		$this->assertSame( '&lt;b&gt; &amp; &quot;x&quot;', $escaper->attribute( '<b> & "x"' ) );
	}

	public function test_clock_is_in_the_site_time_zone(): void {
		update_option( 'timezone_string', 'Europe/Berlin' );

		$this->assertSame( 'Europe/Berlin', ( new \SW\Postlist\Infrastructure\WordPress\Engine\WpClock() )->now()->getTimezone()->getName() );
	}
}
