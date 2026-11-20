<?php
/**
 * Authenticated source discovery, dry run and confirmed form imports.
 *
 * @package SWForms
 * @since 0.8.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Import\Registry;
use SWForms\WordPress\Support\Capabilities;

final class ImportController extends BaseController {
	public function register_routes(): void {
		foreach ( array(
			array( '/importers', 'GET', 'catalog' ),
			array( '/importers/(?P<adapter>[a-z][a-z0-9-]{0,39})/forms', 'GET', 'forms' ),
			array( '/importers/(?P<adapter>[a-z][a-z0-9-]{0,39})/forms/(?P<id>\d+)', 'GET', 'preview' ),
			array( '/importers/(?P<adapter>[a-z][a-z0-9-]{0,39})/forms/(?P<id>\d+)', 'POST', 'import_form' ),
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
	}

	public function catalog(): \WP_REST_Response {
		return $this->response( array( 'items' => Registry::instance()->catalog() ) );
	}

	public function forms( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$adapter = Registry::instance()->get( (string) $request['adapter'] );
		if ( null === $adapter ) {
			return $this->missing();
		}
		$page = min( 10000, max( 1, (int) ( $request['page'] ?? 1 ) ) );
		return $this->response(
			array(
				'items' => $adapter->forms( $page ),
				'page'  => $page,
			)
		);
	}

	public function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$report = $this->report( $request );
		return $report instanceof \WP_Error ? $report : $this->response( $report );
	}

	public function import_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$report = $this->report( $request );
		if ( $report instanceof \WP_Error ) {
			return $report;
		}
		$body = $this->body( $request );
		if ( ! is_string( $body['fingerprint'] ?? null ) || ! hash_equals( $report['fingerprint'], $body['fingerprint'] ) ) {
			return $this->error( 'swf_import_changed', __( 'The import report has changed. Review it again before importing.', 'sw-forms' ), 409 );
		}
		$service = FormService::create_default();
		// Store the validated document as an unpublished draft; no notifications or hooks run.
		$id = $service->repository()->create( $report['title'], $report['document'], 'inactive' );
		if ( $id < 1 ) {
			return $this->error( 'swf_import_failed', __( 'The imported form could not be saved.', 'sw-forms' ), 500 );
		}
		return $this->response(
			array(
				'id'     => $id,
				'report' => $report,
			),
			201
		);
	}

	/** @return array{title: string, document: array<string, mixed>, imported: list<string>, skipped: list<string>, warnings: list<string>, fingerprint: string}|\WP_Error */
	private function report( \WP_REST_Request $request ): array|\WP_Error {
		$adapter = Registry::instance()->get( (string) $request['adapter'] );
		$source  = $adapter?->read( (int) $request['id'] );
		if ( null === $source ) {
			return $this->missing();
		}
		$service            = FormService::create_default();
		$source['title']    = sanitize_text_field( $source['title'] );
		$source['document'] = $service->normalize( $source['document'] );
		// Delivery settings from third-party adapters cannot activate outbound requests.
		$source['document']['settings']['notifications'] = array();
		$source['document']['settings']['webhooks']      = array();
		$errors = $service->validate( $source['document'] );
		if ( $errors || empty( $source['document']['fields'] ) ) {
			return $this->error(
				'swf_import_invalid',
				__( 'No valid form could be imported. The source may contain only unsupported fields.', 'sw-forms' ),
				422,
				array(
					'errors' => $errors,
					'report' => $source,
				)
			);
		}
		return $source + array( 'fingerprint' => hash( 'sha256', (string) wp_json_encode( array( (string) $request['adapter'], (int) $request['id'], $source ) ) ) );
	}

	/** @param array<string, mixed> $data */
	private function response( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private function missing(): \WP_Error {
		return $this->error( 'swf_import_missing', __( 'Import source unavailable. Activate the source plugin and choose an existing form.', 'sw-forms' ), 404 );
	}
}
