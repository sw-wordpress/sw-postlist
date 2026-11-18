<?php
/**
 * Shared behaviour of the private admin routes: capability and object checks, JSON bodies and uniform errors.
 *
 * Authentication is WordPress' cookie authentication: without a valid REST nonce the request is treated as a guest
 * and fails the permission callback, which protects every route against CSRF.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Presentation\Rest;

use SW\Postlist\Domain\Validation\ValidationError;
use SW\Postlist\Infrastructure\WordPress\Capabilities;

abstract class BaseAdminController {

	public const NAMESPACE = 'sw-postlist/v1';

	abstract public function register(): void;

	public function can_manage(): bool {
		return current_user_can( Capabilities::manage_capability() );
	}

	/**
	 * Management capability plus the object based capability of the stored document.
	 */
	public function can_edit_object( \WP_REST_Request $request ): bool {
		if ( ! $this->can_manage() ) {
			return false;
		}
		// A missing document is answered with 404 by the callback; an existing one needs its object capability.
		return ! get_post( (int) $request['id'] ) instanceof \WP_Post || current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function body( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return $this->array_or_empty( $params );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function array_or_empty( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string, mixed> $data Additional response data.
	 */
	protected function error( string $code, string $message, int $status, array $data = array() ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array_merge(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$data
			),
			$status
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * @param array<string, mixed> $data Payload.
	 */
	protected function ok( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * @param list<ValidationError> $errors Findings.
	 */
	protected function invalid( array $errors ): \WP_REST_Response {
		return $this->error(
			'invalid_document',
			'The document is not valid.',
			422,
			array( 'errors' => array_map( static fn ( ValidationError $error ): array => $error->to_array(), $errors ) )
		);
	}
}
