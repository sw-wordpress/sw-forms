<?php
/**
 * Authenticated test-send and resend endpoints.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\Core\Form\Tree;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Support\Queue;
use SWForms\WordPress\Webhooks\DeliveryRepository;
use SWForms\WordPress\Webhooks\DeliveryService;
use SWForms\WordPress\Webhooks\RequestBuilder;
use SWForms\WordPress\Webhooks\SampleSubmission;
use SWForms\WordPress\Webhooks\Transport;
use SWForms\WordPress\Webhooks\WebhookValidator;

final class WebhooksController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/webhooks/(?P<webhook>[A-Za-z0-9_-]+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_webhook' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_FORMS ),
				'args'                => $this->id_arg(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/entries/(?P<id>\d+)/webhooks/(?P<delivery>\d+)/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE_FORMS ) && current_user_can( Capabilities::MANAGE_ENTRIES ),
				'args'                => $this->id_arg(),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function test_webhook( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$service = FormService::create_default();
		$form    = $service->repository()->find( $id );
		if ( null === $form ) {
			return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
		}
		$document = $service->editable_document( $id );
		$hook     = null;
		foreach ( $document['settings']['webhooks'] ?? array() as $item ) {
			if ( $item['id'] === $request['webhook'] ) {
				$hook = $item;
				break;
			}
		}
		if ( null === $hook ) {
			return $this->error( 'swf_not_found', __( 'Webhook not found.', 'sw-forms' ), 404 );
		}
		$hook['enabled'] = true;
		$normalized      = $service->normalize( $document );
		if ( array() !== $service->validate( $normalized ) || array() !== WebhookValidator::validate( array( $hook ), Tree::build( $document['fields'], FieldRegistry::instance() ) ) ) {
			return $this->error( 'swf_invalid_form', __( 'Save a valid webhook configuration before sending a test.', 'sw-forms' ), 422 );
		}
		try {
			$builder     = new RequestBuilder();
			$payload     = $builder->payload( SampleSubmission::create( $form, $document ), $hook, wp_generate_uuid4(), 'webhook.test' );
			$args        = $builder->build( $payload, $hook );
			$args['url'] = $hook['url'];
			return new \WP_REST_Response( ( new Transport() )->send( $args ) );
		} catch ( \Throwable $error ) {
			return $this->error( 'swf_webhook_error', __( 'Webhook could not be prepared. Check its signing secret and configuration.', 'sw-forms' ), 422 );
		}
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function resend( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$delivery = (int) $request['delivery'];
		$repo     = new DeliveryRepository();
		$row      = $repo->find( $delivery );
		if ( null === ( new EntryRepository() )->find_visible( $id ) || null === $row || (int) $row['entry_id'] !== $id ) {
			return $this->error( 'swf_not_found', __( 'Entry not found.', 'sw-forms' ), 404 );
		}
		if ( ! $repo->resend( $delivery, $id ) ) {
			return $this->error( 'swf_webhook_busy', __( 'This delivery is already queued or running.', 'sw-forms' ), 409 );
		}
		try {
			( new Queue() )->schedule( DeliveryService::HOOK, array( $delivery ) );
		} catch ( \Throwable $error ) {
			// Recovery schedules the persisted pending row.
			return new \WP_REST_Response( array( 'queued' => true ), 202 );
		}
		return new \WP_REST_Response( array( 'queued' => true ), 202 );
	}
}
