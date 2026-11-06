<?php
/**
 * Administrator entry editing and notes, separate from visitor submission endpoints.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Entries\EntryEditor;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Support\Capabilities;

final class EntryEditingController extends BaseController {

	public function register_routes(): void {
		foreach ( array( 'GET', 'PUT' ) as $method ) {
			register_rest_route(
				self::NAMESPACE,
				'/entries/(?P<id>\d+)/values',
				array(
					'methods'             => $method,
					'callback'            => array( $this, 'values' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_ENTRIES ),
					'args'                => $this->id_arg(),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/entries/(?P<id>\d+)/notes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'note' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_ENTRIES ),
				'args'                => $this->id_arg(),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function values( \WP_REST_Request $request ) {
		$entry = ( new EntryRepository() )->find_visible( (int) $request['id'] );
		if ( null === $entry ) {
			return $this->error( 'swf_not_found', __( 'Entry not found.', 'sw-forms' ), 404 ); }
		$editor = new EntryEditor();
		if ( 'GET' === $request->get_method() ) {
			return new \WP_REST_Response( $editor->definition( $entry ) ); }
		$body   = $this->body( $request );
		$result = $editor->save( $entry, is_array( $body['values'] ?? null ) ? $body['values'] : array(), is_string( $body['revision'] ?? null ) ? $body['revision'] : '' );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function note( \WP_REST_Request $request ) {
		$repo  = new EntryRepository();
		$entry = $repo->find_visible( (int) $request['id'] );
		if ( null === $entry ) {
			return $this->error( 'swf_not_found', __( 'Entry not found.', 'sw-forms' ), 404 ); }
		$body = $this->body( $request );
		$note = is_string( $body['note'] ?? null ) ? trim( sanitize_textarea_field( $body['note'] ) ) : '';
		if ( '' === $note || mb_strlen( $note ) > 2000 ) {
			return $this->error( 'swf_invalid_note', __( 'Enter a note of at most 2000 characters.', 'sw-forms' ), 422 );
		}
		$repo->log( $entry['id'], $entry['formId'], 'note', (string) get_current_user_id(), 'success', $note );
		return new \WP_REST_Response( array( 'saved' => true ), 201 );
	}
}
