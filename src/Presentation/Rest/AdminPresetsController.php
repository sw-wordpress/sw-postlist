<?php
/**
 * Private admin routes for presets: list, save as user preset, duplicate, reset/delete and apply.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Application\Port\DocumentRepositoryException;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Preset\PresetValidator;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Domain\Preset\Preset;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateCompilation;
use SW\Postlist\Infrastructure\WordPress\PresetRepository;
use SW\Postlist\TemplateEngine\TemplateError;

final class AdminPresetsController extends BaseAdminController {

	public function __construct(
		private readonly PresetRepository $presets,
		private readonly PresetValidator $validator,
		private readonly TemplateRepositoryInterface $templates,
		private readonly TemplateCompilation $compilation,
		private readonly SourceRegistry $sources
	) {
	}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/presets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/presets/(?P<preset>[a-z0-9-]{1,40})',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'reset' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		foreach ( array( 'duplicate', 'apply' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/admin/presets/(?P<preset>[a-z0-9-]{1,40})/' . $action,
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
		foreach ( $this->presets->all() as $entry ) {
			$items[] = array_merge(
				$entry['preset']->to_array(),
				array(
					'origin'    => $entry['origin'],
					'overrides' => $entry['overrides'],
					'can_reset' => PresetRepository::ORIGIN_USER === $entry['origin'],
				)
			);
		}
		return $this->ok(
			array(
				'presets'     => $items,
				'diagnostics' => $this->presets->diagnostics(),
			)
		);
	}

	/**
	 * Saves a user preset; the same ID overwrites a bundled preset (and "reset" deletes the override again).
	 */
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		$body   = $this->body( $request );
		$result = $this->validator->validate( is_array( $body['preset'] ?? null ) ? $body['preset'] : array() );
		if ( ! $result->is_valid() ) {
			return $this->invalid( $result->errors() );
		}
		$this->presets->save_user( $result->value() );
		return $this->ok( array( 'id' => $result->value()->id ), 201 );
	}

	public function reset( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (string) $request['preset'];
		if ( ! $this->presets->delete_user( $id ) ) {
			return null === $this->presets->find( $id )
				? $this->error( 'preset_not_found', 'The preset does not exist.', 404 )
				: $this->error( 'preset_not_resettable', 'Bundled presets cannot be deleted; only user presets and overrides can.', 409 );
		}
		return $this->ok( array( 'id' => $id ) );
	}

	/**
	 * Copies any preset to a new user preset.
	 */
	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response {
		$found = $this->presets->find( (string) $request['preset'] );
		if ( null === $found ) {
			return $this->error( 'preset_not_found', 'The preset does not exist.', 404 );
		}
		$body = $this->body( $request );
		$copy = $found['preset']->to_array();
		if ( is_string( $body['id'] ?? null ) ) {
			$copy['id'] = $body['id'];
		}
		if ( is_string( $body['title'] ?? null ) ) {
			$copy['title'] = $body['title'];
		}
		if ( $copy['id'] === $found['preset']->id ) {
			return $this->error( 'preset_id_taken', 'Choose a new ID for the copy.', 422 );
		}
		if ( null !== $this->presets->find( (string) $copy['id'] ) ) {
			return $this->error( 'preset_id_taken', 'A preset with this ID already exists.', 409 );
		}
		$result = $this->validator->validate( $copy );
		if ( ! $result->is_valid() ) {
			return $this->invalid( $result->errors() );
		}
		$this->presets->save_user( $result->value() );
		return $this->ok( array( 'id' => $result->value()->id ), 201 );
	}

	/**
	 * Creates ordinary container and entry templates from the preset, validated for the chosen source. Nothing is
	 * written when one of them does not compile.
	 */
	public function apply( \WP_REST_Request $request ): \WP_REST_Response {
		$found = $this->presets->find( (string) $request['preset'] );
		if ( null === $found ) {
			return $this->error( 'preset_not_found', 'The preset does not exist.', 404 );
		}
		$body   = $this->body( $request );
		$source = is_string( $body['source'] ?? null ) ? $this->sources->get( $body['source'] ) : null;
		if ( null === $source ) {
			return $this->error( 'source_required', 'Choose an available entry source.', 422 );
		}
		$preset    = $found['preset'];
		$documents = array(
			'container' => new TemplateDocument( $preset->title . ' (container)', TemplateRole::Container, $preset->container['html'], $preset->container['css'] ),
			'entry'     => new TemplateDocument( $preset->title . ' (entry)', TemplateRole::Entry, $preset->entry['html'], $preset->entry['css'] ),
		);
		foreach ( $documents as $role => $document ) {
			$compiled = $this->compilation->validate( $document, $source );
			if ( ! $compiled->is_valid() ) {
				return $this->error(
					'preset_template_invalid',
					'A template of the preset does not compile for this source.',
					422,
					array(
						'role'   => $role,
						'errors' => array_map( static fn ( TemplateError $error ): array => $error->to_array(), $compiled->errors ),
					)
				);
			}
		}
		try {
			$container_id = $this->templates->save( null, $documents['container'] );
			$entry_id     = $this->templates->save( null, $documents['entry'] );
		} catch ( DocumentRepositoryException $error ) {
			return $this->error( $error->error_code, $error->getMessage(), 422 );
		}
		return $this->ok(
			array(
				'container_template' => $container_id,
				'entry_template'     => $entry_id,
				'layout'             => $preset->layout,
			),
			201
		);
	}
}
