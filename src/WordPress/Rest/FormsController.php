<?php
/**
 * Forms: list, create, read, save (publish), autosave draft, status, duplicate, delete; field types.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Frontend\Preview;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Webhooks\DeliveryRepository;

final class FormsController extends BaseController {

	private FormService $service;

	public function __construct() {
		$this->service = FormService::create_default();
	}

	public function register_routes(): void {
		$manage = $this->can( Capabilities::MANAGE_FORMS );
		register_rest_route(
			self::NAMESPACE,
			'/forms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_forms' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_FORMS, Capabilities::VIEW_ENTRIES ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_form' ),
					'permission_callback' => $manage,
					'args'                => array(
						'title' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_form' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_FORMS, Capabilities::VIEW_ENTRIES ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'save_form' ),
					'permission_callback' => $manage,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_status' ),
					'permission_callback' => $manage,
					'args'                => $this->id_arg() + array(
						'status' => array(
							'type'     => 'string',
							'enum'     => array( 'active', 'inactive' ),
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_form' ),
					'permission_callback' => $manage,
					'args'                => $this->id_arg(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/draft',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'save_draft' ),
					'permission_callback' => $manage,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'discard_draft' ),
					'permission_callback' => $manage,
					'args'                => $this->id_arg(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'duplicate_form' ),
				'permission_callback' => $manage,
				'args'                => $this->id_arg(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/field-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'field_types' ),
				'permission_callback' => $manage,
			)
		);
	}

	public function list_forms(): \WP_REST_Response {
		$failures = ( new DeliveryRepository() )->failure_counts();
		$items    = array();
		foreach ( $this->service->repository()->all() as $form ) {
			$items[] = $form + array( 'webhookFailures' => $failures[ $form['id'] ] ?? 0 );
		}
		return new \WP_REST_Response( array( 'items' => $items ) );
	}

	public function create_form( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $this->service->create( (string) $request['title'] );
		return new \WP_REST_Response( $this->present( $id ), 201 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_form( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->service->repository()->find( $id ) ) {
			return $this->not_found();
		}
		return new \WP_REST_Response( $this->present( $id ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_form( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->service->repository()->find( $id ) ) {
			return $this->not_found();
		}
		$body     = $this->body( $request );
		$document = is_array( $body['document'] ?? null ) ? $body['document'] : array();
		$result   = $this->service->save( $id, (string) ( $body['title'] ?? '' ), $document );
		if ( array() !== $result['errors'] ) {
			// Keep the work: the invalid document is stored as draft.
			$this->service->repository()->save_draft( $id, $result['document'] );
			return $this->error( 'swf_invalid_form', __( 'The form could not be saved. Please fix the marked problems.', 'sw-forms' ), 422, array( 'errors' => $result['errors'] ) );
		}
		return new \WP_REST_Response( $this->present( $id ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_draft( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->service->repository()->find( $id ) ) {
			return $this->not_found();
		}
		$body = $this->body( $request );
		$this->service->save_draft( $id, is_array( $body['document'] ?? null ) ? $body['document'] : array() );
		return new \WP_REST_Response(
			array(
				'saved'      => true,
				'previewUrl' => Preview::url( $id ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function discard_draft( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$form = $this->service->repository()->find( $id );
		if ( null === $form ) {
			return $this->not_found();
		}
		if ( $form['current_version'] > 0 ) {
			$published = $this->service->repository()->published( $id );
			$this->service->repository()->publish( $id, $form['title'], is_array( $published ) ? $published : array() );
		}
		return new \WP_REST_Response( $this->present( $id ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_status( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->service->repository()->find( $id ) ) {
			return $this->not_found();
		}
		$this->service->repository()->update_status( $id, (string) $request['status'] );
		return new \WP_REST_Response( $this->present( $id ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function duplicate_form( \WP_REST_Request $request ) {
		$copy = $this->service->duplicate( (int) $request['id'] );
		if ( 0 === $copy ) {
			return $this->not_found();
		}
		return new \WP_REST_Response( $this->present( $copy ), 201 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_form( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->service->repository()->find( $id ) ) {
			return $this->not_found();
		}
		$this->service->repository()->delete( $id );
		/**
		 * Fires after a form and all its entries were deleted.
		 *
		 * @since 0.1.0
		 *
		 * @param int $form_id Form id.
		 */
		do_action( 'swf_form_deleted', $id );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	public function field_types(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'items' => FieldRegistry::instance()->definitions() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present( int $id ): array {
		$repository = $this->service->repository();
		$form       = $repository->find( $id );
		if ( null === $form ) {
			return array();
		}
		$published = $repository->published( $id );
		return array(
			'id'             => $form['id'],
			'title'          => $form['title'],
			'status'         => $form['status'],
			'currentVersion' => $form['current_version'],
			'hasDraft'       => null !== $form['draft'],
			// Secrets stay on the server; the builder only learns whether they are set.
			'document'       => $this->service->secrets()->decorate( $id, $this->service->editable_document( $id ) ),
			// Keys of the published version: the builder warns before a key that receivers may rely on changes.
			'publishedKeys'  => (object) self::keys( is_array( $published['fields'] ?? null ) ? $published['fields'] : array() ),
			'previewUrl'     => Preview::url( $id ),
			'shortcode'      => '[sw_form id="' . $id . '"]',
			'updatedAt'      => mysql_to_rfc3339( $form['updated_at'] ),
		);
	}

	/**
	 * @param array<mixed> $elements
	 * @return array<string, string> Element id => key.
	 */
	private static function keys( array $elements ): array {
		$out = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['key'] ) && '' !== $element['key'] ) {
				$out[ (string) $element['id'] ] = (string) $element['key'];
			}
			$out += self::keys( is_array( $element['children'] ?? null ) ? $element['children'] : array() );
			foreach ( is_array( $element['columns'] ?? null ) ? $element['columns'] : array() as $column ) {
				$out += self::keys( is_array( $column['children'] ?? null ) ? $column['children'] : array() );
			}
		}
		return $out;
	}

	private function not_found(): \WP_Error {
		return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
	}
}
