<?php
/**
 * Private admin routes for templates: list, load, publish/update in one step, validate, trash and preview.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Application\Document\TemplateDocumentValidator;
use SW\Postlist\Application\Port\DocumentRepositoryException;
use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Domain\View\ViewDocument;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateCompilation;
use SW\Postlist\Infrastructure\WordPress\Render\RenderRequest;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\TemplateEngine\TemplateError;

final class AdminTemplatesController extends BaseAdminController {

	public function __construct(
		private readonly TemplateRepositoryInterface $templates,
		private readonly ViewRepositoryInterface $views,
		private readonly TemplateDocumentValidator $validator,
		private readonly TemplateCompilation $compilation,
		private readonly SourceRegistry $sources,
		private readonly ViewRenderer $renderer
	) {
	}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/templates',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/templates/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_edit_object' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_edit_object' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'trash' ),
					'permission_callback' => array( $this, 'can_edit_object' ),
				),
			)
		);
		foreach ( array( 'validate', 'preview' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/admin/templates/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	public function index(): \WP_REST_Response {
		$items = array();
		foreach ( $this->templates->summaries() as $summary ) {
			$summary['used_by'] = count( $this->views->view_ids_using_template( $summary['id'] ) );
			$items[]            = $summary;
		}
		return $this->ok( array( 'templates' => $items ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$template_id = (int) $request['id'];
		$document    = $this->templates->find( $template_id );
		if ( null === $document ) {
			return $this->error( 'template_not_found', 'The template does not exist.', 404 );
		}
		return $this->ok(
			array(
				'id'       => $template_id,
				'document' => $document->to_array(),
				'used_by'  => $this->views->view_ids_using_template( $template_id ),
			)
		);
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->publish( null, $request );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->publish( (int) $request['id'], $request );
	}

	/**
	 * "Publish" and "Update" are one step: validate against the chosen source and every source of the views that use the
	 * template, then write. On any failure the last valid public version stays in place.
	 */
	private function publish( ?int $template_id, \WP_REST_Request $request ): \WP_REST_Response {
		$body   = $this->body( $request );
		$result = $this->validator->validate( is_array( $body['document'] ?? null ) ? $body['document'] : array() );
		if ( ! $result->is_valid() ) {
			return $this->invalid( $result->errors() );
		}
		$document = $result->value();
		$sources  = $this->sources_to_check( $body['source'] ?? null, $template_id );
		if ( array() === $sources ) {
			return $this->error( 'source_required', 'Choose the entry source the template is validated against.', 422 );
		}
		$failures = array();
		foreach ( $sources as $source ) {
			$compiled = $this->compilation->validate( $document, $source );
			if ( ! $compiled->is_valid() ) {
				$failures[ $source->id() ] = array_map( static fn ( TemplateError $error ): array => $error->to_array(), $compiled->errors );
			}
		}
		if ( array() !== $failures ) {
			return $this->error( 'template_invalid', 'The template does not compile.', 422, array( 'sources' => $failures ) );
		}
		try {
			$saved = $this->templates->save( $template_id, $document );
		} catch ( DocumentRepositoryException $error ) {
			return $this->error( $error->error_code, $error->getMessage(), 422 );
		}
		return $this->ok(
			array(
				'id'       => $saved,
				'document' => $document->to_array(),
			),
			null === $template_id ? 201 : 200
		);
	}

	/**
	 * The chosen source plus the sources of all views using the template.
	 *
	 * @return list<EntrySourceInterface>
	 */
	private function sources_to_check( mixed $chosen, ?int $template_id ): array {
		$ids = array();
		if ( is_string( $chosen ) ) {
			$ids[ $chosen ] = $chosen;
		}
		foreach ( null === $template_id ? array() : $this->views->view_ids_using_template( $template_id ) as $view_id ) {
			$view = $this->views->find( $view_id );
			if ( $view instanceof ViewDocument ) {
				$ids[ $view->source_id ] = $view->source_id;
			}
		}
		$sources = array();
		foreach ( $ids as $source_id ) {
			$source = $this->sources->get( $source_id );
			if ( null !== $source ) {
				$sources[] = $source;
			}
		}
		return $sources;
	}

	public function trash( \WP_REST_Request $request ): \WP_REST_Response {
		$template_id = (int) $request['id'];
		if ( null === $this->templates->find( $template_id ) ) {
			return $this->error( 'template_not_found', 'The template does not exist.', 404 );
		}
		$used_by = $this->views->view_ids_using_template( $template_id );
		if ( array() !== $used_by ) {
			return $this->error( 'template_in_use', 'The template is used by views. Assign another template first.', 409, array( 'used_by' => $used_by ) );
		}
		return $this->templates->trash( $template_id )
			? $this->ok( array( 'id' => $template_id ) )
			: $this->error( 'trash_failed', 'The template could not be moved to the trash.', 500 );
	}

	/**
	 * Live validation for the editor: syntax, allowlist, placeholders, formatters and CSS with line and column.
	 */
	public function validate( \WP_REST_Request $request ): \WP_REST_Response {
		$prepared = $this->prepare( $request );
		if ( $prepared instanceof \WP_REST_Response ) {
			return $prepared;
		}
		$compiled = $this->compilation->validate( $prepared['document'], $prepared['source'] );
		return $this->ok(
			array(
				'valid'  => $compiled->is_valid(),
				'errors' => array_map( static fn ( TemplateError $error ): array => $error->to_array(), $compiled->errors ),
			)
		);
	}

	/**
	 * Renders the unsaved template with real public entries of the source.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$prepared = $this->prepare( $request );
		if ( $prepared instanceof \WP_REST_Response ) {
			return $prepared;
		}
		$document = $prepared['document'];
		$view     = $this->sample_view( $prepared['source'] );
		$result   = $this->renderer->render_document(
			$view,
			0,
			new RenderRequest(
				'preview',
				1,
				null,
				null,
				array(),
				true,
				false,
				array(),
				TemplateRole::Container === $document->role ? $document : null,
				TemplateRole::Entry === $document->role ? $document : null
			)
		);
		return $this->ok(
			array(
				'html'  => $result->html,
				'css'   => $result->css,
				'count' => $result->count,
				'error' => $result->error_code,
			)
		);
	}

	/**
	 * @return array{document: TemplateDocument, source: EntrySourceInterface}|\WP_REST_Response
	 */
	private function prepare( \WP_REST_Request $request ): array|\WP_REST_Response {
		$body = $this->body( $request );
		$raw  = is_array( $body['document'] ?? null ) ? $body['document'] : array();
		// Validation and preview judge the content; a missing title is the business of publishing, not of the preview.
		if ( ! is_string( $raw['title'] ?? null ) || '' === trim( $raw['title'] ) ) {
			$raw['title'] = 'Untitled';
		}
		$result = $this->validator->validate( $raw );
		if ( ! $result->is_valid() ) {
			return $this->invalid( $result->errors() );
		}
		$source = is_string( $body['source'] ?? null ) ? $this->sources->get( $body['source'] ) : null;
		if ( null === $source ) {
			return $this->error( 'source_required', 'Choose an available entry source.', 422 );
		}
		return array(
			'document' => $result->value(),
			'source'   => $source,
		);
	}

	private function sample_view( EntrySourceInterface $source ): ViewDocument {
		$result = ( new \SW\Postlist\Application\Document\ViewDocumentValidator() )->validate(
			array(
				'schema_version' => 1,
				'title'          => 'Preview',
				'source'         => $source->id(),
				'query'          => array(
					'per_page' => 3,
					'orderby'  => 'date',
					'order'    => 'desc',
				),
				'layout'         => array( 'type' => 'grid' ),
				'pagination'     => array( 'mode' => 'none' ),
				'templates'      => array(),
			)
		);
		return $result->value();
	}
}
