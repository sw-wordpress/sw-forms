<?php
/**
 * Queues the webhooks configured in the form settings. Delivery happens in the background
 * ({@see DeliveryService}), so a slow or unavailable receiver never delays the visitor.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Logic\Evaluator;
use SWForms\Core\Webhook\NetworkGuard;
use SWForms\WordPress\Actions\ActionInterface;
use SWForms\WordPress\Queue\Queue;
use SWForms\WordPress\Submission\Submission;

final class WebhookAction implements ActionInterface {

	public function __construct( private readonly DeliveryRepository $deliveries = new DeliveryRepository() ) {}

	public function type(): string {
		return 'webhook';
	}

	public function run( Submission $submission ): void {
		$webhooks  = $submission->document['settings']['webhooks'] ?? array();
		$evaluator = new Evaluator( $submission->tree );
		foreach ( is_array( $webhooks ) ? $webhooks : array() as $webhook ) {
			if ( empty( $webhook['enabled'] ) ) {
				continue;
			}
			$id = (string) $webhook['id'];
			if ( ! $evaluator->conditionMet( $webhook['logic'] ?? null, $submission->values ) ) {
				$submission->log( $this->type(), $id, 'skipped', __( 'Condition not met.', 'sw-forms' ) );
				continue;
			}
			$event_id = wp_generate_uuid4();
			$payload  = Payloads::build(
				$webhook,
				$submission->tree,
				$submission->data,
				array(
					'event'    => Payloads::EVENT_SUBMITTED,
					'event_id' => $event_id,
					'form'     => array(
						'id'      => $submission->form_id(),
						'title'   => (string) $submission->form['title'],
						'version' => $submission->version,
					),
					'entry'    => array(
						'id'        => $submission->entry_id > 0 ? $submission->entry_id : null,
						'createdAt' => gmdate( 'c' ),
						'sourceUrl' => (string) ( $submission->meta['sourceUrl'] ?? '' ),
					),
				)
			);
			/**
			 * Filters the payload of a webhook before it is queued.
			 *
			 * @since 0.2.0
			 *
			 * @param array<string, mixed> $payload    Envelope `{event, eventId, form, entry, data}` or only the data.
			 * @param array<string, mixed> $webhook    Webhook settings.
			 * @param Submission           $submission The submission.
			 */
			$payload     = (array) apply_filters( 'swf_webhook_payload', $payload, $webhook, $submission );
			$delivery_id = $this->deliveries->insert( $submission->form_id(), $submission->entry_id, $id, Payloads::EVENT_SUBMITTED, $event_id, $payload );
			if ( 0 === $delivery_id || ! Queue::schedule( DeliveryService::HOOK, array( $delivery_id ) ) ) {
				$submission->log( $this->type(), $id, 'failed', __( 'The delivery could not be queued.', 'sw-forms' ) );
				continue;
			}
			$host = NetworkGuard::parseUrl( (string) $webhook['url'] )['host'];
			/* translators: %s: host name of the webhook URL */
			$submission->log( $this->type(), $id, 'queued', sprintf( __( 'Queued for %s.', 'sw-forms' ), $host ) );
		}
	}
}
