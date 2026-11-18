<?php
/**
 * The visual builder (TypeScript) produces templates that the PHP engine accepts for the post source: the shared
 * fixtures in tests/contract/builder are compiled with the real schema, formatters and allowlist.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;

class BuilderOutputTest extends SWP_TestCase {

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fixtures(): array {
		$fixtures = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/contract/builder/*.json' ) ?: array() as $file ) {
			$fixtures[] = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
		}
		return $fixtures;
	}

	public function test_generated_templates_compile_for_the_post_source(): void {
		update_option( 'sw_postlist_settings', array( 'meta_keys' => array( 'post:post' => array( 'subtitle', 'price' ) ) ) );
		$fixtures = $this->fixtures();
		$this->assertCount( 3, $fixtures );

		foreach ( $fixtures as $fixture ) {
			$document = new TemplateDocument( $fixture['name'], TemplateRole::Entry, $fixture['expect']['html'], $fixture['expect']['css'] );
			$result   = $this->services->template_compilation()->validate( $document, $this->services->sources()->get( 'post:post' ) );

			$this->assertTrue( $result->is_valid(), $fixture['name'] . ': ' . wp_json_encode( array_map( static fn ( $error ) => $error->to_array(), $result->errors ) ) );
		}
	}

	public function test_generated_output_renders_with_real_entries(): void {
		update_option( 'sw_postlist_settings', array( 'meta_keys' => array( 'post:post' => array( 'subtitle', 'price' ) ) ) );
		$cat     = self::factory()->category->create( array( 'name' => 'News' ) );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Built post', 'post_excerpt' => 'Short words here', 'post_category' => array( $cat ), 'meta_input' => array( 'subtitle' => 'Sub', 'price' => '' ) ) );
		$everything = array_values( array_filter( $this->fixtures(), static fn ( array $fixture ): bool => 'builder-everything' === $fixture['name'] ) )[0];
		$template   = $this->services->templates()->save( null, new TemplateDocument( 'Built', TemplateRole::Entry, $everything['expect']['html'], $everything['expect']['css'] ) );
		$view_id    = $this->services->views()->save( null, $this->view_document( array( 'templates' => array( 'entry' => $template ) ) ) );

		$html = do_shortcode( '[sw_postlist id="' . $view_id . '"]' );

		$this->assertStringContainsString( 'Built post', $html );
		$this->assertStringContainsString( '<h2 class="swp-b-title">Built post</h2>', $html );
		$this->assertStringContainsString( '<p class="swp-b-meta">Sub</p>', $html );
		$this->assertStringContainsString( 'on request', $html, 'The fallback of an empty meta value.' );
		$this->assertStringContainsString( 'News', $html );
		$this->assertStringContainsString( 'data-swp-builder="', $html );
	}
}
