<?php
/**
 * View documents stored in the internal swp_view post type; template references are kept queryable.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Port\DocumentRepositoryException;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Domain\View\ViewDocument;

final class WpViewRepository implements ViewRepositoryInterface {

	public function __construct(
		private readonly DocumentStore $store,
		private readonly ViewDocumentValidator $validator,
		private readonly TemplateRepositoryInterface $templates
	) {
	}

	public function save( ?int $view_id, ViewDocument $document, string $status = 'publish' ): int {
		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			throw new DocumentRepositoryException( 'status_invalid', 'A view is either published or a draft.' );
		}
		$this->assert_template_references( $document );

		$post = array(
			'post_type'   => PostTypes::VIEW,
			'post_status' => $status,
			'post_title'  => $document->title,
			'post_name'   => '',
		);
		if ( null !== $view_id ) {
			if ( PostTypes::VIEW !== get_post_type( $view_id ) ) {
				throw new DocumentRepositoryException( 'view_not_found', 'The view does not exist.' );
			}
			$post['ID'] = $view_id;
		}
		$saved = null === $view_id ? wp_insert_post( wp_slash( $post ), true ) : wp_update_post( wp_slash( $post ), true );
		if ( is_wp_error( $saved ) ) {
			throw new DocumentRepositoryException( 'view_write_failed', 'The view could not be saved.' );
		}
		$this->store->write( $saved, $document->to_array() );
		$this->write_references( $saved, $document->template_ids() );
		return $saved;
	}

	public function find( int $view_id ): ?ViewDocument {
		if ( PostTypes::VIEW !== get_post_type( $view_id ) || ! in_array( get_post_status( $view_id ), array( 'publish', 'draft' ), true ) ) {
			return null;
		}
		$raw = $this->store->read( $view_id );
		if ( null === $raw ) {
			return null;
		}
		$result = $this->validator->validate( $raw );
		return $result->is_valid() ? $result->value() : null;
	}

	public function view_ids_using_template( int $template_id ): array {
		$ids = get_posts(
			array(
				'post_type'              => PostTypes::VIEW,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'            => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed lookup on a handful of internal documents.
					array(
						'key'   => PostTypes::META_USES_TEMPLATE,
						'value' => (string) $template_id,
					),
				),
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	public function summaries(): array {
		$posts   = get_posts(
			array(
				'post_type'      => PostTypes::VIEW,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$summary = array();
		foreach ( $posts as $post ) {
			$raw       = $this->store->read( $post->ID );
			$layout    = is_array( $raw['layout'] ?? null ) && is_string( $raw['layout']['type'] ?? null ) ? $raw['layout']['type'] : null;
			$summary[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'source'   => is_string( $raw['source'] ?? null ) ? $raw['source'] : null,
				'layout'   => $layout,
				'modified' => mysql_to_rfc3339( $post->post_modified_gmt ) ?: '',
			);
		}
		return $summary;
	}

	public function trash( int $view_id ): bool {
		return PostTypes::VIEW === get_post_type( $view_id ) && false !== wp_trash_post( $view_id );
	}

	public function status( int $view_id ): ?string {
		$status = PostTypes::VIEW === get_post_type( $view_id ) ? get_post_status( $view_id ) : false;
		return in_array( $status, array( 'publish', 'draft' ), true ) ? (string) $status : null;
	}

	/**
	 * @throws DocumentRepositoryException When a referenced template is missing or has the wrong role.
	 */
	private function assert_template_references( ViewDocument $document ): void {
		$expected = array(
			'container' => array( $document->container_template_id, TemplateRole::Container ),
			'entry'     => array( $document->entry_template_id, TemplateRole::Entry ),
		);
		foreach ( $expected as $role_name => list( $template_id, $role ) ) {
			if ( null === $template_id ) {
				continue;
			}
			$template = $this->templates->find( $template_id );
			if ( null === $template ) {
				throw new DocumentRepositoryException( 'template_missing', 'A referenced template does not exist.', array( 'role' => $role_name ) );
			}
			if ( $template->role !== $role ) {
				throw new DocumentRepositoryException( 'template_role_mismatch', 'A referenced template has the wrong role.', array( 'role' => $role_name ) );
			}
		}
	}

	/**
	 * @param list<int> $template_ids Template IDs the view references.
	 */
	private function write_references( int $view_id, array $template_ids ): void {
		delete_post_meta( $view_id, PostTypes::META_USES_TEMPLATE );
		foreach ( $template_ids as $template_id ) {
			add_post_meta( $view_id, PostTypes::META_USES_TEMPLATE, (string) $template_id );
		}
	}
}
