<?php
/**
 * Shared helpers for REST controllers. Every route has an explicit permission callback.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

abstract class BaseController {

	public const NAMESPACE = 'swforms/v1';

	abstract public function register_routes(): void;

	/**
	 * @return callable(): bool
	 */
	protected function can( string ...$capabilities ): callable {
		return static function () use ( $capabilities ): bool {
			foreach ( $capabilities as $capability ) {
				if ( current_user_can( $capability ) ) {
					return true;
				}
			}
			return false;
		};
	}

	/**
	 * @param array<string, mixed> $data Additional error data.
	 */
	protected function error( string $code, string $message, int $status = 400, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) + $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function body( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function pagination_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
