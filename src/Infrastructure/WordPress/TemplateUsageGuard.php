<?php
/**
 * Keeps used templates from being trashed or deleted on every WordPress route, and reconciles references of views
 * that come back from the trash or from a revision (PLAN.md §6).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Port\ViewRepositoryInterface;

final class TemplateUsageGuard {

	/**
	 * Collaborators are resolved lazily: the guard is registered while the plugin loads, long before every
	 * extension had the chance to register its filters.
	 *
	 * @param \Closure(): ViewRepositoryInterface $views     Provides the view repository.
	 * @param \Closure(): DocumentStore           $store     Provides the document store.
	 * @param \Closure(): ViewDocumentValidator   $validator Provides the view validator.
	 */
	public function __construct(
		private readonly \Closure $views,
		private readonly \Closure $store,
		private readonly \Closure $validator
	) {
	}

	public function register(): void {
		add_filter( 'pre_trash_post', array( $this, 'block_trash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'block_delete' ), 10, 2 );
		add_action( 'untrashed_post', array( $this, 'reconcile_view' ) );
		add_action( 'wp_restore_post_revision', array( $this, 'reconcile_restored_revision' ), 20, 1 );
	}

	/**
	 * @param bool|null $trash Short-circuit value.
	 * @param \WP_Post  $post  Post about to be trashed.
	 * @return bool|null False blocks the trash.
	 */
	public function block_trash( ?bool $trash, \WP_Post $post ): ?bool {
		return $this->is_used( $post ) ? false : $trash;
	}

	/**
	 * @param \WP_Post|false|null $delete Short-circuit value.
	 * @param \WP_Post            $post   Post about to be deleted.
	 * @return \WP_Post|false|null False blocks the deletion.
	 */
	public function block_delete( $delete, \WP_Post $post ) {
		return $this->is_used( $post ) ? false : $delete;
	}

	/**
	 * Views that return from the trash may point to templates that were removed meanwhile.
	 */
	public function reconcile_view( int $post_id ): void {
		if ( PostTypes::VIEW !== get_post_type( $post_id ) ) {
			return;
		}
		$raw = ( $this->store )()->read( $post_id );
		if ( null === $raw ) {
			return;
		}
		$templates = is_array( $raw['templates'] ?? null ) ? $raw['templates'] : array();
		$changed   = false;
		foreach ( array( 'container', 'entry' ) as $role ) {
			$template_id = $templates[ $role ] ?? null;
			if ( is_int( $template_id ) && PostTypes::TEMPLATE !== get_post_type( $template_id ) ) {
				$templates[ $role ] = null;
				$changed            = true;
			}
		}
		if ( $changed ) {
			$raw['templates'] = $templates;
			( $this->store )()->write( $post_id, $raw );
		}
		$result = ( $this->validator )()->validate( $raw );
		delete_post_meta( $post_id, PostTypes::META_USES_TEMPLATE );
		if ( $result->is_valid() ) {
			foreach ( $result->value()->template_ids() as $template_id ) {
				add_post_meta( $post_id, PostTypes::META_USES_TEMPLATE, (string) $template_id );
			}
		}
	}

	/**
	 * A restored revision brings its own document back: bring the reference rows in line with it.
	 */
	public function reconcile_restored_revision( int $post_id ): void {
		$this->reconcile_view( $post_id );
	}

	private function is_used( \WP_Post $post ): bool {
		return PostTypes::TEMPLATE === $post->post_type && array() !== ( $this->views )()->view_ids_using_template( $post->ID );
	}
}
