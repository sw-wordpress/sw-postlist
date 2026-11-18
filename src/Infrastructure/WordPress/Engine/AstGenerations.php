<?php
/**
 * Cache generations: an invalidation bumps a counter of the affected source or template (or the global one). The next
 * render builds a different cache key and compiles again; nothing is recompiled eagerly, so a bulk import of posts
 * does not trigger a wave of compilations.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

final class AstGenerations {

	public const OPTION = 'sw_postlist_ast_generations';

	/**
	 * Current generation state as part of a cache key.
	 */
	public function key( string $source_id, int|string $template_id ): string {
		$state = $this->state();
		return 'g' . $state['all'] . '.s' . ( $state['sources'][ $source_id ] ?? 0 ) . '.t' . ( $state['templates'][ (string) $template_id ] ?? 0 );
	}

	public function bump_source( string $source_id ): void {
		$state                          = $this->state();
		$state['sources'][ $source_id ] = ( $state['sources'][ $source_id ] ?? 0 ) + 1;
		$this->store( $state );
	}

	public function bump_template( int|string $template_id ): void {
		$state                                       = $this->state();
		$state['templates'][ (string) $template_id ] = ( $state['templates'][ (string) $template_id ] ?? 0 ) + 1;
		$this->store( $state );
	}

	public function bump_all(): void {
		$state        = $this->state();
		$state['all'] = $state['all'] + 1;
		$this->store( $state );
	}

	/**
	 * @return array{all: int, sources: array<string, int>, templates: array<string, int>}
	 */
	private function state(): array {
		$stored  = get_option( self::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$sources = array();
		foreach ( is_array( $stored['sources'] ?? null ) ? $stored['sources'] : array() as $key => $value ) {
			$sources[ (string) $key ] = (int) $value;
		}
		$templates = array();
		foreach ( is_array( $stored['templates'] ?? null ) ? $stored['templates'] : array() as $key => $value ) {
			$templates[ (string) $key ] = (int) $value;
		}
		return array(
			'all'       => (int) ( $stored['all'] ?? 0 ),
			'sources'   => $sources,
			'templates' => $templates,
		);
	}

	/**
	 * @param array{all: int, sources: array<string, int>, templates: array<string, int>} $state New state.
	 */
	private function store( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
