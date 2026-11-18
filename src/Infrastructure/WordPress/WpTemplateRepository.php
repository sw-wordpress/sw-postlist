<?php
/**
 * Template documents stored in the internal swp_template post type.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Document\TemplateDocumentValidator;
use SW\Postlist\Application\Port\DocumentRepositoryException;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Domain\Template\TemplateDocument;

final class WpTemplateRepository implements TemplateRepositoryInterface {

	public function __construct(
		private readonly DocumentStore $store,
		private readonly TemplateDocumentValidator $validator
	) {
	}

	public function save( ?int $template_id, TemplateDocument $document ): int {
		$post = array(
			'post_type'   => PostTypes::TEMPLATE,
			'post_status' => 'publish',
			'post_title'  => $document->title,
			'post_name'   => '',
		);
		if ( null !== $template_id ) {
			if ( PostTypes::TEMPLATE !== get_post_type( $template_id ) ) {
				throw new DocumentRepositoryException( 'template_not_found', 'The template does not exist.' );
			}
			$post['ID'] = $template_id;
		}
		$saved = null === $template_id ? wp_insert_post( wp_slash( $post ), true ) : wp_update_post( wp_slash( $post ), true );
		if ( is_wp_error( $saved ) ) {
			throw new DocumentRepositoryException( 'template_write_failed', 'The template could not be saved.' );
		}
		$this->store->write( $saved, $document->to_array() );
		/**
		 * Fires after a template version was written; compiled ASTs of the template are invalidated.
		 *
		 * @since 0.1.0
		 *
		 * @param int $template_id Template ID.
		 */
		do_action( 'sw_postlist_template_saved', $saved );
		return $saved;
	}

	public function find( int $template_id ): ?TemplateDocument {
		if ( PostTypes::TEMPLATE !== get_post_type( $template_id ) || 'publish' !== get_post_status( $template_id ) ) {
			return null;
		}
		$raw = $this->store->read( $template_id );
		if ( null === $raw ) {
			return null;
		}
		$result = $this->validator->validate( $raw );
		return $result->is_valid() ? $result->value() : null;
	}

	public function summaries(): array {
		$posts   = get_posts(
			array(
				'post_type'      => PostTypes::TEMPLATE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$summary = array();
		foreach ( $posts as $post ) {
			$raw       = $this->store->read( $post->ID );
			$summary[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'role'     => is_string( $raw['role'] ?? null ) ? $raw['role'] : '',
				'modified' => mysql_to_rfc3339( $post->post_modified_gmt ) ?: '',
			);
		}
		return $summary;
	}

	public function trash( int $template_id ): bool {
		// The usage guard refuses the trash while a view references the template.
		return PostTypes::TEMPLATE === get_post_type( $template_id ) && false !== wp_trash_post( $template_id );
	}
}
