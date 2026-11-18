<?php
/**
 * The public reload route of dynamic views: filters, AJAX pagination, "load more" and infinite scroll.
 *
 * It uses the one render function and serves exactly what the page would show for the same URL state: only published
 * views, only visible entries, only filters the view configures. There is no nonce: the route is read-only and returns
 * public data, so a nonce would only break page caches. See docs/rest-api.md for the cache behavior.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\OriginSigner;
use SW\Postlist\Infrastructure\WordPress\Render\RenderRequest;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;

final class PublicViewController {

	public const NAMESPACE = 'sw-postlist/v1';

	/** Longest accepted query string of the embedding page. */
	public const MAX_QUERY_LENGTH = 2000;

	public function __construct( private readonly ViewRenderer $renderer, private readonly OriginSigner $signer = new OriginSigner() ) {
	}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/views/(?P<id>\d+)/render',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'render' ),
				// Public by design: published views and visible entries only (see class description).
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'key'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9_-]{1,32}$',
					),
					'query' => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => self::MAX_QUERY_LENGTH,
					),
					'token' => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 1000,
					),
				),
			)
		);
	}

	public function render( \WP_REST_Request $request ): \WP_REST_Response {
		$view_id  = (int) $request['id'];
		$key      = (string) $request['key'];
		$query    = self::parse_query( (string) $request['query'] );
		$state    = '' === (string) $request['token'] ? null : $this->signer->verify_embed( (string) $request['token'], $view_id, $key );
		$result   = $this->renderer->render(
			$view_id,
			new RenderRequest(
				$key,
				InstanceRegistry::page_from( $query, $key ),
				null,
				null === $state ? null : $state['origin'],
				null === $state ? array() : $state['overrides'],
				false,
				false,
				$query
			)
		);
		$response = new \WP_REST_Response(
			array(
				'html'     => $result->html,
				'total'    => $result->total,
				'has_more' => $result->has_more,
				'page'     => $result->page,
				'count'    => $result->count,
				'error'    => $result->error_code,
			),
			null === $result->error_code ? 200 : 404
		);
		/**
		 * Cache-Control header of the public reload route. The default revalidates on every request so that content
		 * withdrawn after a page was cached never lingers; sites with a full page cache may allow a short max-age.
		 *
		 * @since 0.7.0
		 *
		 * @param string $header  Header value.
		 * @param int    $view_id View ID.
		 */
		$response->header( 'Cache-Control', (string) apply_filters( 'sw_postlist_public_cache_control', 'no-cache', $view_id ) );
		return $response;
	}

	/**
	 * Query parameters of the embedding page, sanitized like the ones of a normal page request.
	 *
	 * @return array<string, mixed>
	 */
	public static function parse_query( string $query ): array {
		$query = ltrim( substr( $query, 0, self::MAX_QUERY_LENGTH ), '?' );
		$out   = array();
		parse_str( $query, $out );
		return map_deep( $out, 'sanitize_text_field' );
	}
}
