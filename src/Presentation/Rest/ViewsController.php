<?php
/**
 * REST routes of the views: a list for the block editor and the protected preview that uses the shared renderer.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Infrastructure\WordPress\Capabilities;
use SW\Postlist\Infrastructure\WordPress\PostTypes;
use SW\Postlist\Infrastructure\WordPress\Render\InstanceRegistry;
use SW\Postlist\Infrastructure\WordPress\Render\RenderRequest;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;

final class ViewsController {

	public const NAMESPACE = 'sw-postlist/v1';

	public function __construct( private readonly ViewRenderer $renderer ) {
	}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/views',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_views' ),
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/views/(?P<id>\d+)/preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::manage_capability() ),
				'args'                => array(
					'id'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'page' => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'maximum'           => InstanceRegistry::MAX_PAGE,
						'sanitize_callback' => 'absint',
					),
					'key'  => array(
						'type'    => 'string',
						'default' => 'preview',
						'pattern' => '^[a-z0-9_-]{1,32}$',
					),
				),
			)
		);
	}

	/**
	 * Published views as id and title only; enough to choose one in the block.
	 */
	public function list_views(): \WP_REST_Response {
		$posts = get_posts(
			array(
				'post_type'      => PostTypes::VIEW,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
			);
		}
		return new \WP_REST_Response( $items );
	}

	/**
	 * Preview of a stored (published or draft) view with the public data set.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$view_id  = (int) $request['id'];
		$key      = (string) $request['key'];
		$result   = $this->renderer->render(
			$view_id,
			new RenderRequest( $key, max( 1, (int) $request['page'] ), null, null, array(), true, false, array() )
		);
		$response = new \WP_REST_Response(
			array(
				'html'     => $result->html,
				'css'      => $result->css,
				'total'    => $result->total,
				'has_more' => $result->has_more,
				'page'     => $result->page,
				'count'    => $result->count,
				'error'    => $result->error_code,
			),
			null === $result->error_code ? 200 : 404
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
