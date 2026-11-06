<?php
/**
 * Authenticated CSV download. WordPress cookie authentication still requires a REST nonce.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Entries\CsvExport;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Support\Capabilities;

final class ExportController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/entries/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => $this->can( Capabilities::VIEW_ENTRIES ),
				'callback'            => array( $this, 'download' ),
				'args'                => $this->id_arg() + array(
					'filter' => array(
						'type'    => 'string',
						'enum'    => EntryRepository::FILTERS,
						'default' => 'inbox',
					),
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order'  => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'desc',
					),
				),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function download( \WP_REST_Request $request ) {
		if ( null === ( new FormRepository() )->find( (int) $request['id'] ) ) {
			return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
		}
		return new \WP_REST_Response(
			null,
			200,
			array(
				'Content-Type'           => 'text/csv; charset=UTF-8',
				'Content-Disposition'    => 'attachment; filename="swforms-' . (int) $request['id'] . '.csv"',
				'Cache-Control'          => 'no-store, private',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
	}

	public function serve( bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
		if ( $served || 200 !== $response->get_status() || ! preg_match( '#^/swforms/v1/forms/\d+/entries/export$#', $request->get_route() ) ) {
			return $served;
		}
		$stream = fopen( 'php://output', 'wb' );
		if ( false !== $stream ) {
			( new CsvExport() )->write( $stream, (int) $request['id'], $request['filter'], $request['search'], $request['order'] );
			fclose( $stream );
		}
		return true;
	}
}
