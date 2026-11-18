<?php
/**
 * Private admin routes for the JSON export and import package and for duplicating views and templates.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Application\Package\PackageService;
use SW\Postlist\Application\Port\DocumentRepositoryException;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\View\ViewDocument;

final class AdminPackageController extends BaseAdminController {

	public function __construct(
		private readonly PackageService $packages,
		private readonly ViewRepositoryInterface $views,
		private readonly TemplateRepositoryInterface $templates
	) {
	}

	public function register(): void {
		foreach ( array(
			'/admin/export' => 'export',
			'/admin/import' => 'import',
		) as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
		foreach ( array(
			'views'     => 'duplicate_view',
			'templates' => 'duplicate_template',
		) as $collection => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/admin/' . $collection . '/(?P<id>\d+)/duplicate',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_edit_object' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
		}
	}

	/**
	 * Body: `{views: [id], templates: [id], presets: [slug]}`. Responds with the package.
	 */
	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$body   = $this->body( $request );
		$result = $this->packages->export(
			$this->ids( $body['views'] ?? null ),
			$this->ids( $body['templates'] ?? null ),
			$this->slugs( $body['presets'] ?? null )
		);
		if ( array() !== $result['missing'] ) {
			return $this->error( 'export_item_missing', 'Some of the selected items do not exist (any more).', 404, array( 'missing' => $result['missing'] ) );
		}
		$package = $result['package'];
		if ( array() === $package['views'] && array() === (array) $package['templates'] && array() === $package['presets'] ) {
			return $this->error( 'export_empty', 'Choose at least one item to export.', 422 );
		}
		return $this->ok( array( 'package' => $package ) );
	}

	/**
	 * Body: `{package: {...}, dry_run: bool}`. A dry run only validates.
	 */
	public function import( \WP_REST_Request $request ): \WP_REST_Response {
		if ( strlen( $request->get_body() ) > PackageService::MAX_BYTES ) {
			return $this->error( 'package_too_large', 'The package is larger than 2 MB.', 413 );
		}
		$body    = $this->body( $request );
		$dry_run = isset( $body['dry_run'] ) && true === $body['dry_run'];
		$report  = $this->packages->import( $body['package'] ?? null, $dry_run );
		return $this->ok( $report->to_array(), $report->ok ? 200 : 422 );
	}

	public function duplicate_view( \WP_REST_Request $request ): \WP_REST_Response {
		$source = $this->views->find( (int) $request['id'] );
		if ( ! $source instanceof ViewDocument ) {
			return $this->error( 'view_not_found', 'The view does not exist.', 404 );
		}
		$copy = new ViewDocument(
			$this->copy_title( $source->title, array_column( $this->views->summaries(), 'title' ) ),
			$source->source_id,
			$source->query,
			$source->layout,
			$source->pagination,
			$source->container_template_id,
			$source->entry_template_id,
			$source->filters
		);
		try {
			// Templates stay shared: the copy references the same documents, a change reaches both views.
			$id = $this->views->save( null, $copy, 'draft' );
		} catch ( DocumentRepositoryException $error ) {
			return $this->error( $error->error_code, $error->getMessage(), 422 );
		}
		return $this->ok(
			array(
				'id'       => $id,
				'status'   => 'draft',
				'document' => $copy->to_array(),
			),
			201
		);
	}

	public function duplicate_template( \WP_REST_Request $request ): \WP_REST_Response {
		$source = $this->templates->find( (int) $request['id'] );
		if ( ! $source instanceof TemplateDocument ) {
			return $this->error( 'template_not_found', 'The template does not exist.', 404 );
		}
		$copy = new TemplateDocument( $this->copy_title( $source->title, array_column( $this->templates->summaries(), 'title' ) ), $source->role, $source->html, $source->css );
		try {
			$id = $this->templates->save( null, $copy );
		} catch ( DocumentRepositoryException $error ) {
			return $this->error( $error->error_code, $error->getMessage(), 422 );
		}
		return $this->ok(
			array(
				'id'       => $id,
				'document' => $copy->to_array(),
			),
			201
		);
	}

	/**
	 * @param list<string> $existing Titles in use.
	 */
	private function copy_title( string $title, array $existing ): string {
		/* translators: %s: title of the original */
		$base      = sprintf( __( '%s (copy)', 'sw-postlist' ), $title );
		$candidate = $base;
		$number    = 2;
		while ( $number < 1000 && in_array( $candidate, $existing, true ) ) {
			$candidate = $base . ' ' . $number;
			++$number;
		}
		return mb_substr( $candidate, 0, 200 );
	}

	/**
	 * @return list<int>
	 */
	private function ids( mixed $value ): array {
		$ids = array();
		foreach ( is_array( $value ) ? array_slice( $value, 0, PackageService::MAX_VIEWS + PackageService::MAX_TEMPLATES ) : array() as $item ) {
			if ( is_int( $item ) && $item > 0 ) {
				$ids[] = $item;
			}
		}
		return $ids;
	}

	/**
	 * @return list<string>
	 */
	private function slugs( mixed $value ): array {
		$slugs = array();
		foreach ( is_array( $value ) ? array_slice( $value, 0, PackageService::MAX_PRESETS ) : array() as $item ) {
			if ( is_string( $item ) && 1 === preg_match( '/^[a-z0-9-]{1,40}$/', $item ) ) {
				$slugs[] = $item;
			}
		}
		return $slugs;
	}
}
