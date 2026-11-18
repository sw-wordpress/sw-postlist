<?php
/**
 * Private admin routes for views: list, load, save, validate, trash and preview of unsaved documents.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Port\DocumentRepositoryException;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateCompilation;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\RenderRequest;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;

final class AdminViewsController extends BaseAdminController {

	public function __construct(
		private readonly ViewRepositoryInterface $views,
		private readonly TemplateRepositoryInterface $templates,
		private readonly ViewDocumentValidator $validator,
		private readonly ViewRenderer $renderer,
		private readonly TemplateCompilation $compilation,
		private readonly \SW\Postlist\Application\Query\SourceRegistry $sources
	) {
	}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/views',
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
			'/admin/views/(?P<id>\d+)',
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
		register_rest_route(
			self::NAMESPACE,
			'/admin/views/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/views/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function index(): \WP_REST_Response {
		return $this->ok( array( 'views' => $this->views->summaries() ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$view_id  = (int) $request['id'];
		$document = $this->views->find( $view_id );
		$status   = $this->views->status( $view_id );
		if ( null === $document || null === $status ) {
			return $this->error( 'view_not_found', 'The view does not exist or is damaged.', 404 );
		}
		return $this->ok(
			array(
				'id'       => $view_id,
				'status'   => $status,
				'document' => $document->to_array(),
			)
		);
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->save( null, $request );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->save( (int) $request['id'], $request );
	}

	/**
	 * Saving is a single step: the document is validated (schema, template references), written and, for a published
	 * view, its templates must compile for the view's source. A failure leaves the stored version untouched.
	 */
	private function save( ?int $view_id, \WP_REST_Request $request ): \WP_REST_Response {
		$body   = $this->body( $request );
		$status = is_string( $body['status'] ?? null ) ? $body['status'] : 'publish';
		$raw    = is_array( $body['document'] ?? null ) ? $body['document'] : array();
		$result = $this->validator->validate( $raw );
		if ( ! $result->is_valid() ) {
			return $this->invalid( $result->errors() );
		}
		$document = $result->value();
		if ( 'publish' === $status ) {
			$problem = $this->template_problem( $document );
			if ( null !== $problem ) {
				return $problem;
			}
		}
		try {
			$saved = $this->views->save( $view_id, $document, $status );
		} catch ( DocumentRepositoryException $error ) {
			return $this->error( $error->error_code, $error->getMessage(), 422, array( 'details' => $error->details ) );
		}
		return $this->ok(
			array(
				'id'       => $saved,
				'status'   => (string) $this->views->status( $saved ),
				'document' => $document->to_array(),
			),
			null === $view_id ? 201 : 200
		);
	}

	/**
	 * Publishing a view checks that the referenced templates exist, fit their role and compile for the view's source.
	 */
	private function template_problem( \SW\Postlist\Domain\View\ViewDocument $document ): ?\WP_REST_Response {
		$source = $this->sources->get( $document->source_id );
		if ( null === $source ) {
			return $this->error( 'source_missing', 'The entry source of the view is not available.', 422 );
		}
		$errors = array();
		foreach ( array(
			array( $document->container_template_id, TemplateRole::Container ),
			array( $document->entry_template_id, TemplateRole::Entry ),
		) as list( $template_id, $role ) ) {
			if ( null === $template_id ) {
				continue;
			}
			$template = $this->templates->find( $template_id );
			if ( null === $template || $template->role !== $role ) {
				$errors[] = array(
					'code'     => 'template_missing',
					'template' => $template_id,
				);
				continue;
			}
			$compiled = $this->compilation->compile( $template_id, $source );
			if ( ! $compiled->is_valid() ) {
				$errors[] = array(
					'code'     => 'template_invalid',
					'template' => $template_id,
					'errors'   => array_map( static fn ( $error ): array => $error->to_array(), $compiled->errors ),
				);
			}
		}
		return array() === $errors ? null : $this->error( 'template_references_invalid', 'A referenced template cannot be used by this view.', 422, array( 'templates' => $errors ) );
	}

	public function trash( \WP_REST_Request $request ): \WP_REST_Response {
		$view_id = (int) $request['id'];
		if ( null === $this->views->status( $view_id ) ) {
			return $this->error( 'view_not_found', 'The view does not exist.', 404 );
		}
		return $this->views->trash( $view_id )
			? $this->ok( array( 'id' => $view_id ) )
			: $this->error( 'trash_failed', 'The view could not be moved to the trash.', 500 );
	}

	public function validate( \WP_REST_Request $request ): \WP_REST_Response {
		$body   = $this->body( $request );
		$result = $this->validator->validate( is_array( $body['document'] ?? null ) ? $body['document'] : array() );
		return $this->ok(
			array(
				'valid'  => $result->is_valid(),
				'errors' => array_map( static fn ( $error ): array => $error->to_array(), $result->errors() ),
			)
		);
	}

	/**
	 * Renders an unsaved document with the public data set, optionally with unsaved templates.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$body   = $this->body( $request );
		$result = $this->validator->validate( is_array( $body['document'] ?? null ) ? $body['document'] : array() );
		if ( ! $result->is_valid() ) {
			return $this->invalid( $result->errors() );
		}
		$page     = isset( $body['page'] ) && is_int( $body['page'] ) ? max( 1, min( InstanceRegistry::MAX_PAGE, $body['page'] ) ) : 1;
		$rendered = $this->renderer->render_document(
			$result->value(),
			0,
			new RenderRequest(
				'preview',
				$page,
				null,
				null,
				array(),
				true,
				false,
				array(),
				$this->unsaved_template( $body['container_template'] ?? null, TemplateRole::Container ),
				$this->unsaved_template( $body['entry_template'] ?? null, TemplateRole::Entry )
			)
		);
		return $this->ok(
			array(
				'html'     => $rendered->html,
				'css'      => $rendered->css,
				'total'    => $rendered->total,
				'has_more' => $rendered->has_more,
				'page'     => $rendered->page,
				'count'    => $rendered->count,
				'error'    => $rendered->error_code,
			)
		);
	}

	private function unsaved_template( mixed $raw, TemplateRole $role ): ?TemplateDocument {
		if ( ! is_array( $raw ) || ! is_string( $raw['html'] ?? null ) ) {
			return null;
		}
		$css = is_string( $raw['css'] ?? null ) ? $raw['css'] : '';
		return strlen( $raw['html'] ) > \SW\Postlist\Application\Document\TemplateDocumentValidator::MAX_HTML_BYTES || strlen( $css ) > \SW\Postlist\Application\Document\TemplateDocumentValidator::MAX_CSS_BYTES
			? null
			: new TemplateDocument( 'Preview', $role, $raw['html'], $css );
	}
}
