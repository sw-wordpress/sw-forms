<?php
/**
 * Form portability, starter templates and immutable version history.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Forms\PortableDocument;
use SWForms\WordPress\Forms\Templates;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Webhooks\WebhookSettings;

final class FormToolsController extends BaseController {
	public function register_routes(): void {
		foreach ( array(
			array( '/form-templates', 'GET', 'templates' ),
			array( '/forms/import', 'POST', 'import_form' ),
			array( '/forms/(?P<id>\d+)/export', 'GET', 'export_form' ),
			array( '/forms/(?P<id>\d+)/versions', 'GET', 'versions' ),
			array( '/forms/(?P<id>\d+)/versions/(?P<version>\d+)', 'GET', 'version' ),
			array( '/forms/(?P<id>\d+)/versions/(?P<version>\d+)/restore', 'POST', 'restore' ),
		) as list( $route, $method, $callback ) ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $this->can( Capabilities::MANAGE_FORMS ),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/forms/embed-options',
			array(
				'methods'             => 'GET',
				'callback'            => static fn (): \WP_REST_Response => new \WP_REST_Response( array( 'items' => \SWForms\WordPress\Editor\Embeds::choices() ) ),
				'permission_callback' => $this->can( Capabilities::MANAGE_FORMS, 'edit_posts' ),
			)
		);
	}

	public function templates(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'items' => Templates::all() ) );
	}

	public function export_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$service = FormService::create_default();
		$form    = $service->repository()->find( (int) $request['id'] );
		if ( null === $form ) {
			return $this->missing();
		}
		$response = new \WP_REST_Response(
			array(
				'format'        => 'swforms',
				'formatVersion' => 1,
				'title'         => $form['title'],
				'document'      => PortableDocument::clean( $service->editable_document( $form['id'] ) ),
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	public function import_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( $request->get_body() ) > 1048576 ) {
			return $this->error( 'swf_import', __( 'Choose a valid SW Forms JSON export (up to 1 MB).', 'sw-forms' ) );
		}
		$body = $this->body( $request );
		if ( 'swforms' !== ( $body['format'] ?? '' ) || 1 !== ( $body['formatVersion'] ?? null ) || ! is_array( $body['document'] ?? null ) || ! is_array( $body['document']['fields'] ?? null ) || ! array_is_list( $body['document']['fields'] ) || 1 !== ( $body['document']['schemaVersion'] ?? 1 ) ) {
			return $this->error( 'swf_import', __( 'Choose a valid SW Forms JSON export (up to 1 MB).', 'sw-forms' ) );
		}
		$service  = FormService::create_default();
		$document = $service->normalize( PortableDocument::clean( $body['document'] ) );
		$errors   = $service->validate( $document );
		if ( $errors ) {
			return $this->error( 'swf_invalid_form', __( 'The form could not be saved. Please fix the marked problems.', 'sw-forms' ), 422, array( 'errors' => $errors ) );
		}
		$title = sanitize_text_field( is_string( $body['title'] ?? null ) ? $body['title'] : '' );
		$id    = $service->create( $title );
		$service->repository()->update_status( $id, 'inactive' );
		$service->save( $id, $title, $document );
		return new \WP_REST_Response( array( 'id' => $id ), 201 );
	}

	public function versions( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new FormRepository();
		if ( null === $repo->find( (int) $request['id'] ) ) {
			return $this->missing();
		}
		return new \WP_REST_Response( array( 'items' => $repo->versions( (int) $request['id'], max( 1, (int) ( $request['page'] ?? 1 ) ) ) ) );
	}

	public function version( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$document = ( new FormRepository() )->version( (int) $request['id'], (int) $request['version'] );
		return null === $document ? $this->missing() : new \WP_REST_Response( array( 'document' => WebhookSettings::redact( $document ) ) );
	}

	public function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$service  = FormService::create_default();
		$document = $service->repository()->version( (int) $request['id'], (int) $request['version'] );
		if ( null === $document ) {
			return $this->missing();
		}
		// Restore to draft, validate first, and preserve current signing secrets by webhook ID.
		$document = $service->normalize( WebhookSettings::redact( $document ) );
		$errors   = $service->validate( $document );
		if ( $errors ) {
			return $this->error( 'swf_invalid_form', __( 'The form could not be saved. Please fix the marked problems.', 'sw-forms' ), 422, array( 'errors' => $errors ) );
		}
		$service->save_draft( (int) $request['id'], $document );
		return new \WP_REST_Response( array( 'restored' => true ) );
	}

	private function missing(): \WP_Error {
		return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
	}
}
