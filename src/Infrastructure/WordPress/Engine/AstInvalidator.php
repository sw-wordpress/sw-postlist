<?php
/**
 * Event driven invalidation of compiled templates (PLAN.md §5): WordPress write paths and integrations bump cache
 * generations; the next render compiles again. Nothing is recompiled eagerly.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\Infrastructure\WordPress\PostTypes;

final class AstInvalidator {

	public const REASON_POST_SAVED     = 'post_saved';
	public const REASON_POST_DELETED   = 'post_deleted';
	public const REASON_META_CHANGED   = 'meta_changed';
	public const REASON_TERMS_CHANGED  = 'terms_changed';
	public const REASON_TEMPLATE_SAVED = 'template_saved';
	public const REASON_INTEGRATION    = 'integration';

	public function __construct(
		private readonly AstGenerations $generations,
		private readonly MetaAllowlist $meta
	) {
	}

	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'post_saved' ), 10, 4 );
		add_action( 'deleted_post', array( $this, 'post_deleted' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'meta_changed' ), 10, 4 );
		}
		add_action( 'set_object_terms', array( $this, 'terms_changed' ), 10, 4 );
		add_action( 'deleted_term_relationships', array( $this, 'terms_removed' ), 10, 3 );
		add_action( 'sw_postlist_template_saved', array( $this, 'template_saved' ) );
		add_action( 'sw_postlist_invalidate_template_ast', array( $this, 'integration' ), 10, 3 );
	}

	/**
	 * Revisions and autosaves are skipped; a type change invalidates the former and the new source.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Saved post.
	 * @param bool          $update      Whether this was an update.
	 * @param \WP_Post|null $post_before Post before the update.
	 */
	public function post_saved( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( 'revision' === $post->post_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( PostTypes::TEMPLATE === $post->post_type || PostTypes::VIEW === $post->post_type ) {
			return;
		}
		$this->bump_source( 'post:' . $post->post_type, self::REASON_POST_SAVED );
		if ( null !== $post_before && $post_before->post_type !== $post->post_type ) {
			$this->bump_source( 'post:' . $post_before->post_type, self::REASON_POST_SAVED );
		}
	}

	public function post_deleted( int $post_id, ?\WP_Post $post = null ): void {
		$post ??= get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'revision' === $post->post_type || PostTypes::TEMPLATE === $post->post_type || PostTypes::VIEW === $post->post_type ) {
			return;
		}
		$this->bump_source( 'post:' . $post->post_type, self::REASON_POST_DELETED );
	}

	/**
	 * Only allowed (template visible) meta keys are relevant.
	 *
	 * @param int|array<int> $meta_id    Meta ID(s).
	 * @param int            $post_id    Post ID.
	 * @param string         $meta_key   Meta key.
	 * @param mixed          $meta_value Meta value.
	 */
	public function meta_changed( $meta_id, int $post_id, string $meta_key, $meta_value = null ): void {
		unset( $meta_id, $meta_value );
		$type = get_post_type( $post_id );
		if ( false === $type || in_array( $type, array( 'revision', PostTypes::TEMPLATE, PostTypes::VIEW ), true ) ) {
			return;
		}
		$source_id = 'post:' . $type;
		if ( in_array( $meta_key, $this->meta->keys( $source_id ), true ) ) {
			$this->bump_source( $source_id, self::REASON_META_CHANGED );
		}
	}

	/**
	 * @param int               $object_id Post ID.
	 * @param array<int, mixed> $terms     Terms.
	 * @param array<int, int>   $tt_ids    Term taxonomy IDs.
	 * @param string            $taxonomy  Taxonomy.
	 */
	public function terms_changed( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
		unset( $terms, $tt_ids );
		$this->terms_event( $object_id, $taxonomy );
	}

	/**
	 * @param int             $object_id Post ID.
	 * @param array<int, int> $tt_ids    Removed term taxonomy IDs.
	 * @param string          $taxonomy  Taxonomy.
	 */
	public function terms_removed( int $object_id, array $tt_ids, string $taxonomy ): void {
		unset( $tt_ids );
		$this->terms_event( $object_id, $taxonomy );
	}

	private function terms_event( int $object_id, string $taxonomy ): void {
		$type = get_post_type( $object_id );
		if ( false !== $type && 'revision' !== $type && is_object_in_taxonomy( $type, $taxonomy ) ) {
			$this->bump_source( 'post:' . $type, self::REASON_TERMS_CHANGED );
		}
	}

	/** A published template affects every source that renders it. */
	public function template_saved( int $template_id ): void {
		$this->generations->bump_template( $template_id );
	}

	/**
	 * Integrations (own entry sources) trigger invalidation explicitly.
	 *
	 * @param string     $reason Reason, for diagnostics.
	 * @param string     $scope  "source", "template" or "all".
	 * @param int|string $id     Source or template ID.
	 */
	public function integration( string $reason, string $scope, $id = '' ): void {
		unset( $reason );
		if ( 'source' === $scope && '' !== (string) $id ) {
			$this->generations->bump_source( (string) $id );
		} elseif ( 'template' === $scope && '' !== (string) $id ) {
			$this->generations->bump_template( $id );
		} elseif ( 'all' === $scope ) {
			$this->generations->bump_all();
		}
	}

	private function bump_source( string $source_id, string $reason ): void {
		/**
		 * Filters whether a content change invalidates the compiled templates of a source.
		 *
		 * Templates are compiled to a structure that does not contain content; sources whose placeholders never
		 * depend on content changes can return false. Changes of templates, profile, schema, formatters and engine
		 * version always take effect through the cache key.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $invalidate Whether to invalidate (default true).
		 * @param string $reason     post_saved, post_deleted, meta_changed or terms_changed.
		 * @param string $source_id  Entry source ID.
		 */
		if ( true === apply_filters( 'sw_postlist_ast_invalidation_scope', true, $reason, $source_id ) ) {
			$this->generations->bump_source( $source_id );
		}
	}
}
