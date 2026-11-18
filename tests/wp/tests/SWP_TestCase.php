<?php
/**
 * Base class: fresh service container and helpers to build views, templates and content.
 *
 * @package sw-postlist
 */

use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Domain\View\ViewDocument;
use SW\Postlist\Infrastructure\WordPress\PostTypes;
use SW\Postlist\Services;

abstract class SWP_TestCase extends WP_UnitTestCase {

	protected Services $services;

	public function set_up(): void {
		parent::set_up();
		Services::reset();
		( new PostTypes() )->register();
		( new \SW\Postlist\Infrastructure\WordPress\Capabilities() )->register();
		$this->services = Services::instance();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Services::reset();
		parent::tear_down();
	}

	protected function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Builds a validated view document; `$overrides` is merged recursively over a minimal valid document.
	 *
	 * @param array<string, mixed> $overrides Overrides of the raw document.
	 */
	protected function view_document( array $overrides = array() ): ViewDocument {
		$raw    = array_replace_recursive(
			array(
				'schema_version' => 1,
				'title'          => 'Test view',
				'source'         => 'post:post',
				'query'          => array(
					'per_page' => 10,
					'orderby'  => 'id',
					'order'    => 'asc',
				),
				'layout'         => array( 'type' => 'grid' ),
				'pagination'     => array( 'mode' => 'classic' ),
				'templates'      => array(),
			),
			$overrides
		);
		$result = $this->services->view_validator()->validate( $raw );
		$this->assertTrue( $result->is_valid(), (string) wp_json_encode( array_map( static fn ( $error ) => $error->to_array(), $result->errors() ) ) );
		return $result->value();
	}

	protected function template_document( string $html = '<article>{{entry.title}}</article>', TemplateRole $role = TemplateRole::Entry, string $title = 'Card' ): TemplateDocument {
		return new TemplateDocument( $title, $role, $html, '.swp-card{color:red}' );
	}

	/**
	 * @param array<string, mixed> $args Extra wp_insert_post arguments.
	 * @return list<int>
	 */
	protected function create_posts( int $count, array $args = array() ): array {
		$ids = array();
		for ( $index = 1; $index <= $count; $index++ ) {
			$ids[] = self::factory()->post->create( array_merge( array( 'post_title' => 'Entry ' . $index ), $args ) );
		}
		return $ids;
	}
}
