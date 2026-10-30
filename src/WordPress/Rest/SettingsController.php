<?php
/**
 * Global settings: `GET|PUT /settings`.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Support\Settings;

final class SettingsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $this->can( Capabilities::SETTINGS ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $this->can( Capabilities::SETTINGS ),
				),
			)
		);
	}

	public function get_settings(): \WP_REST_Response {
		return new \WP_REST_Response( Settings::all() + array( 'captcha' => \SWForms\WordPress\Spam\CaptchaSettings::public_settings() ) );
	}

	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		Settings::update( $this->body( $request ) );
		$body = $this->body( $request );
		if ( is_array( $body['captcha'] ?? null ) ) {
			\SWForms\WordPress\Spam\CaptchaSettings::update( $body['captcha'] ); }
		return $this->get_settings();
	}
}
