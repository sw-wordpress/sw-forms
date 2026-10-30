<?php
/**
 * Public submission endpoint used by the frontend script: `POST /forms/{id}/submit`
 * (multipart or urlencoded body with the same fields as the HTML form).
 *
 * CSRF: anonymous submissions are protected by the signed submission token, not by a nonce
 * (cached pages). Logged-in visitors send the REST nonce from the token endpoint.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Frontend\Postback;
use SWForms\WordPress\Submission\SubmissionService;
use SWForms\WordPress\Support\Capabilities;

final class SubmitController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
				'args'                => $this->id_arg(),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$params  = $request->get_body_params();
		$params  = is_array( $params ) ? $params : array();
		$preview = ! empty( $params['swf_preview'] );
		if ( $preview && ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return $this->error( 'swf_forbidden', __( 'You are not allowed to preview this form.', 'sw-forms' ), 403 );
		}
		$meta            = Postback::request_meta( $params );
		$meta['preview'] = $preview;
		if ( array_key_exists( 'swf_step', $params ) ) {
			$meta['step'] = is_scalar( $params['swf_step'] ) && preg_match( '/^[0-9]{1,3}$/D', (string) $params['swf_step'] ) ? (int) $params['swf_step'] : -1;
		}
		$meta['files'] = $request->get_file_params();
		$result        = SubmissionService::create_default()->submit(
			(int) $request['id'],
			is_array( $params['swf'] ?? null ) ? $params['swf'] : array(),
			$meta
		);
		$response      = new \WP_REST_Response( $result->to_array(), $result->http_status() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
