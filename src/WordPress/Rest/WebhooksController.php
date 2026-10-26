<?php
/**
 * Webhooks: test request, delivery list, resend and dismiss.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Webhooks\DeliveryRepository;
use SWForms\WordPress\Webhooks\DeliveryService;

final class WebhooksController extends BaseController {

	private FormService $forms;
	private DeliveryRepository $deliveries;
	private DeliveryService $service;

	public function __construct() {
		$this->forms      = FormService::create_default();
		$this->deliveries = new DeliveryRepository();
		$this->service    = new DeliveryService( $this->deliveries );
	}

	public function register_routes(): void {
		$manage = $this->can( Capabilities::MANAGE_FORMS );
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/webhooks/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $manage,
				'args'                => $this->id_arg(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/webhooks/deliveries',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_deliveries' ),
				'permission_callback' => $manage,
				'args'                => $this->id_arg() + $this->pagination_args() + array(
					'status'  => array(
						'type'    => 'string',
						'enum'    => array_merge( array( '' ), DeliveryRepository::STATUSES ),
						'default' => '',
					),
					'webhook' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/webhooks/deliveries/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $manage,
				'args'                => $this->id_arg() + array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'resend', 'dismiss' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/deliveries/(?P<id>\d+)/(?P<action>resend|dismiss)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delivery_action' ),
				// Resending from the entry view is part of working with entries.
				'permission_callback' => $this->can( Capabilities::MANAGE_FORMS, Capabilities::MANAGE_ENTRIES ),
				'args'                => $this->id_arg(),
			)
		);
	}

	/**
	 * Body: `{webhook, document?}`. The document defaults to the stored draft or published version.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$form = $this->forms->repository()->find( $id );
		if ( null === $form ) {
			return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
		}
		$body    = $this->body( $request );
		$webhook = is_array( $body['webhook'] ?? null ) ? $body['webhook'] : array();
		if ( '' === trim( is_string( $webhook['url'] ?? null ) ? $webhook['url'] : '' ) ) {
			return $this->error( 'swf_webhook_url', __( 'A webhook needs a target URL.', 'sw-forms' ) );
		}
		$document = is_array( $body['document'] ?? null ) ? $this->forms->normalize( $body['document'] ) : $this->forms->editable_document( $id );
		$result   = $this->service->test( $id, $form['title'], $webhook, $document );
		return new \WP_REST_Response(
			array(
				'success'    => $result['success'],
				'status'     => $result['status'],
				'message'    => $result['message'],
				'durationMs' => $result['duration_ms'],
				'response'   => $result['body'],
				'payload'    => $result['payload'],
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_deliveries( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->forms->repository()->find( $id ) ) {
			return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
		}
		$per_page = (int) $request['per_page'];
		$result   = $this->deliveries->search( $id, (string) $request['status'], (string) $request['webhook'], (int) $request['page'], $per_page );
		return new \WP_REST_Response(
			array(
				'items'    => array_map( array( self::class, 'present' ), $result['items'] ),
				'total'    => $result['total'],
				'pages'    => (int) max( 1, ceil( $result['total'] / max( 1, $per_page ) ) ),
				'failures' => count( $this->deliveries->ids( $id, DeliveryRepository::FAILED ) ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->forms->repository()->find( $id ) ) {
			return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
		}
		$count = 0;
		foreach ( $this->deliveries->ids( $id, DeliveryRepository::FAILED ) as $delivery_id ) {
			if ( 'resend' === $request['action'] ) {
				$count += $this->service->resend( $delivery_id ) ? 1 : 0;
			} else {
				$this->service->dismiss( $delivery_id );
				++$count;
			}
		}
		return new \WP_REST_Response( array( 'affected' => $count ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delivery_action( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$delivery = $this->deliveries->find( $id );
		if ( null === $delivery ) {
			return $this->error( 'swf_not_found', __( 'Delivery not found.', 'sw-forms' ), 404 );
		}
		if ( 'dismiss' === $request['action'] ) {
			$this->service->dismiss( $id );
		} elseif ( DeliveryRepository::PENDING === $delivery['status'] ) {
			return $this->error( 'swf_delivery_pending', __( 'This delivery is still queued.', 'sw-forms' ), 409 );
		} elseif ( ! $this->service->resend( $id ) ) {
			return $this->error( 'swf_delivery_payload', __( 'The data of this delivery is no longer available, it cannot be sent again.', 'sw-forms' ), 410 );
		}
		return new \WP_REST_Response( self::present( (array) $this->deliveries->find( $id ) ) );
	}

	/**
	 * Delivery without its payload (personal data stays on the server).
	 *
	 * @param array<string, mixed> $delivery
	 * @return array<string, mixed>
	 */
	public static function present( array $delivery ): array {
		$out               = array_diff_key( $delivery, array_flip( array( 'payload', 'payloadJson' ) ) );
		$out['resendable'] = null !== ( $delivery['payload'] ?? null ) && DeliveryRepository::PENDING !== ( $delivery['status'] ?? '' );
		return $out;
	}
}
