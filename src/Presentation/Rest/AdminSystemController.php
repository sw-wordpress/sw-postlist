<?php
/**
 * Private admin routes for sources, placeholders and formatters (editor panel), settings and diagnostics.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Application\Filter\FilterTypeRegistry;
use SW\Postlist\Application\Layout\LayoutRegistry;
use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Application\Map\ValueMaps;
use SW\Postlist\Infrastructure\WordPress\Engine\MetaAllowlist;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateEngineFactory;
use SW\Postlist\Infrastructure\WordPress\WpLogger;
use SW\Postlist\TemplateEngine\PlaceholderDefinition;

final class AdminSystemController extends BaseAdminController {

	/**
	 * @param \Closure(): list<array{code: string, message: string, source: string}> $diagnostics Findings of faulty extensions.
	 */
	public function __construct(
		private readonly SourceRegistry $sources,
		private readonly TemplateEngineFactory $engine,
		private readonly \Closure $diagnostics,
		private readonly ?LayoutRegistry $layouts = null,
		private readonly ?FilterTypeRegistry $filter_types = null
	) {
	}

	public function register(): void {
		foreach ( array(
			'/admin/sources'      => 'sources',
			'/admin/placeholders' => 'placeholders',
			'/admin/diagnostics'  => 'diagnostics',
			'/admin/layouts'      => 'layouts',
			'/admin/filter-types' => 'filter_types',
		) as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/admin/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Registered layout types with their option schemas (built-in and third-party).
	 */
	public function layouts(): \WP_REST_Response {
		$items = array();
		foreach ( null === $this->layouts ? array() : $this->layouts->all() as $layout ) {
			$items[] = array(
				'id'     => $layout->id,
				'label'  => $layout->label,
				'module' => $layout->module,
				'schema' => (object) $layout->schema,
			);
		}
		return $this->ok( array( 'layouts' => $items ) );
	}

	/**
	 * Registered visitor filter types with their option schemas (built-in and third-party).
	 */
	public function filter_types(): \WP_REST_Response {
		$registry = $this->filter_types;
		if ( null === $registry ) {
			return $this->ok( array( 'filter_types' => array() ) );
		}
		$items = array();
		foreach ( $registry->all() as $type ) {
			$items[] = array(
				'id'      => $type->id,
				'label'   => $type->label,
				'builtin' => $registry->is_builtin( $type->id ),
				'schema'  => (object) $type->schema,
			);
		}
		return $this->ok( array( 'filter_types' => $items ) );
	}

	public function sources(): \WP_REST_Response {
		$items = array();
		foreach ( $this->sources->all() as $source ) {
			$items[] = array(
				'id'     => $source->id(),
				'label'  => $source->label(),
				'fields' => $source->query_fields(),
			);
		}
		return $this->ok( array( 'sources' => $items ) );
	}

	/**
	 * Placeholders of a source and role plus the typed formatter signatures: everything the editor panel needs.
	 */
	public function placeholders( \WP_REST_Request $request ): \WP_REST_Response {
		$source = is_string( $request['source'] ) ? $this->sources->get( $request['source'] ) : null;
		$role   = in_array( $request['role'], array( 'container', 'entry' ), true ) ? (string) $request['role'] : 'entry';
		if ( ! $source instanceof EntrySourceInterface ) {
			return $this->error( 'source_required', 'Choose an available entry source.', 422 );
		}
		$formatters = array();
		foreach ( $this->engine->formatters()->all() as $name => $formatter ) {
			$formatters[ $name ] = $formatter->signature()->to_editor_array();
		}
		$definitions = array_map(
			static fn ( PlaceholderDefinition $definition ): array => $definition->to_array(),
			$this->engine->context( $source, $role )->schema->all( $role )
		);
		return $this->ok(
			array(
				'source'       => $source->id(),
				'role'         => $role,
				'placeholders' => $definitions,
				'formatters'   => (object) $formatters,
			)
		);
	}

	public function diagnostics(): \WP_REST_Response {
		return $this->ok(
			array(
				'registrations' => ( $this->diagnostics )(),
				'log'           => WpLogger::entries(),
			)
		);
	}

	public function settings(): \WP_REST_Response {
		return $this->ok( $this->stored_settings() );
	}

	/**
	 * Only the documented settings are accepted; meta keys are sanitized like the allowlist itself.
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$body     = $this->body( $request );
		$meta     = array();
		$provided = is_array( $body['meta_keys'] ?? null ) ? $body['meta_keys'] : array();
		foreach ( $provided as $source_id => $keys ) {
			if ( is_string( $source_id ) && null !== $this->sources->get( $source_id ) ) {
				$meta[ $source_id ] = MetaAllowlist::sanitize( $keys );
			}
		}
		$current              = get_option( MetaAllowlist::SETTINGS_OPTION, array() );
		$current              = is_array( $current ) ? $current : array();
		$current['meta_keys'] = $meta;
		if ( array_key_exists( 'maps', $body ) ) {
			$current['maps'] = ValueMaps::sanitize( $body['maps'] );
		}
		update_option( MetaAllowlist::SETTINGS_OPTION, $current, false );
		do_action( 'sw_postlist_invalidate_template_ast', 'settings', 'all' );
		return $this->ok( $this->stored_settings() );
	}

	/**
	 * @return array{meta_keys: array<string, list<string>>, maps: array<string, array{hide: list<string>, labels: array<string, string>, strip_prefix: string}>}
	 */
	private function stored_settings(): array {
		$stored = get_option( MetaAllowlist::SETTINGS_OPTION, array() );
		$keys   = array();
		if ( is_array( $stored ) && is_array( $stored['meta_keys'] ?? null ) ) {
			foreach ( $stored['meta_keys'] as $source_id => $list ) {
				if ( is_string( $source_id ) ) {
					$keys[ $source_id ] = MetaAllowlist::sanitize( $list );
				}
			}
		}
		return array(
			'meta_keys' => $keys,
			'maps'      => ValueMaps::sanitize( is_array( $stored ) ? ( $stored['maps'] ?? array() ) : array() ),
		);
	}
}
