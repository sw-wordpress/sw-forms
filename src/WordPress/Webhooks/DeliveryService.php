<?php
/**
 * Background delivery of webhooks: attempts, retries with backoff, final failure handling
 * (log, admin e-mail, hooks), manual resend, test requests.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Form\Tree;
use SWForms\Core\Webhook\NetworkGuard;
use SWForms\Core\Webhook\RetryPolicy;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Queue\Queue;
use SWForms\WordPress\Support\Settings;

final class DeliveryService {

	public const HOOK         = 'swf_webhook_deliver';
	public const RECOVER_HOOK = 'swf_webhook_recover';
	/** Pending deliveries overdue by this long are queued again by the maintenance. */
	private const RECOVER_AFTER = 900;
	/** At most one failure e-mail per webhook within this period. */
	private const ALERT_INTERVAL = 3600;

	public function __construct(
		private readonly DeliveryRepository $deliveries = new DeliveryRepository(),
		private readonly FormRepository $forms = new FormRepository(),
		private readonly EntryRepository $entries = new EntryRepository(),
		private readonly WebhookSender $sender = new WebhookSender(),
	) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'process' ) );
		add_action( self::RECOVER_HOOK, array( $this, 'recover' ) );
	}

	/** Queue job: one delivery attempt. */
	public function process( mixed $delivery_id ): void {
		$delivery = $this->deliveries->find( (int) $delivery_id );
		if ( null === $delivery || DeliveryRepository::PENDING !== $delivery['status'] ) {
			return;
		}
		$attempt = $delivery['attempts'] + 1;
		$webhook = $this->webhook( $delivery['formId'], $delivery['webhookId'] );
		if ( null === $webhook || empty( $webhook['enabled'] ) ) {
			// Switched off or removed on purpose: no alarm.
			$this->finish( $delivery, $webhook, DeliveryRepository::DISMISSED, $attempt, null === $webhook ? __( 'The webhook no longer exists.', 'sw-forms' ) : __( 'The webhook is switched off.', 'sw-forms' ) );
			return;
		}
		if ( null === $delivery['payload'] ) {
			$this->finish( $delivery, $webhook, DeliveryRepository::FAILED, $attempt, __( 'The data of this delivery is no longer available.', 'sw-forms' ) );
			return;
		}

		$result = $this->sender->send(
			$delivery['formId'],
			$webhook,
			$delivery['payload'],
			array(
				'event'    => $delivery['event'],
				'event_id' => $delivery['eventId'],
				'attempt'  => $attempt,
				'json'     => (string) $delivery['payloadJson'],
			)
		);
		if ( $result['success'] ) {
			$this->finish( $delivery, $webhook, DeliveryRepository::SUCCESS, $attempt, $result['message'], $result );
			return;
		}

		$delay = $result['retryable'] ? $this->policy()->nextDelay( $attempt, $result['status'] ) : null;
		if ( null === $delay ) {
			$this->finish( $delivery, $webhook, DeliveryRepository::FAILED, $attempt, $result['message'], $result );
			return;
		}
		$this->deliveries->record(
			$delivery['id'],
			array(
				'status'          => DeliveryRepository::PENDING,
				'attempts'        => $attempt,
				'response_code'   => (int) $result['status'],
				'message'         => $result['message'],
				'duration_ms'     => $result['duration_ms'],
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			)
		);
		Queue::schedule( self::HOOK, array( $delivery['id'] ), $delay );
		$this->log(
			$delivery,
			'retry',
			/* translators: 1: number of the failed attempt, 2: error, 3: time until the next attempt, e.g. "5 mins" */
			sprintf( __( 'Attempt %1$d failed: %2$s · next attempt in %3$s', 'sw-forms' ), $attempt, $result['message'], human_time_diff( time(), time() + $delay ) )
		);
	}

	/** Queues a delivery again (manual resend), with a fresh attempt counter and the same event id. */
	public function resend( int $delivery_id ): bool {
		$delivery = $this->deliveries->find( $delivery_id );
		if ( null === $delivery || null === $delivery['payload'] ) {
			return false;
		}
		$this->deliveries->requeue( $delivery_id );
		$this->log( $delivery, 'queued', __( 'Resend requested.', 'sw-forms' ) );
		return Queue::schedule( self::HOOK, array( $delivery_id ) );
	}

	/** Acknowledges a failed delivery (removes it from the failure badge). */
	public function dismiss( int $delivery_id ): void {
		$delivery = $this->deliveries->find( $delivery_id );
		if ( null !== $delivery && DeliveryRepository::FAILED === $delivery['status'] ) {
			$this->deliveries->set_status( $delivery_id, DeliveryRepository::DISMISSED );
		}
	}

	/** Queues overdue pending deliveries again (lost queue jobs). Returns the number of deliveries. */
	public function recover(): int {
		$count = 0;
		foreach ( $this->deliveries->overdue( self::RECOVER_AFTER ) as $id ) {
			if ( Queue::scheduler() && false !== as_has_scheduled_action( self::HOOK, array( $id ), Queue::GROUP ) ) {
				continue;
			}
			Queue::schedule( self::HOOK, array( $id ) );
			++$count;
		}
		return $count;
	}

	/**
	 * Sends example data synchronously (test button). The webhook comes from the builder and may
	 * contain unsaved changes including new secrets; missing secrets are taken from the store.
	 *
	 * @param array<string, mixed> $input    Webhook as edited in the builder.
	 * @param array<string, mixed> $document Form document providing the fields.
	 * @return array{status: int|null, success: bool, retryable: bool, message: string, duration_ms: int, body: string, payload: array<string, mixed>}
	 */
	public function test( int $form_id, string $title, array $input, array $document ): array {
		$webhook = WebhookSettings::normalize( $input );
		$fields  = is_array( $document['fields'] ?? null ) ? $document['fields'] : array();
		$headers = array();
		foreach ( is_array( $input['headers'] ?? null ) ? $input['headers'] : array() as $header ) {
			if ( is_array( $header ) && ! empty( $header['secret'] ) && is_scalar( $header['value'] ?? null ) && '' !== trim( (string) $header['value'] ) ) {
				$headers[ strtolower( trim( (string) ( $header['name'] ?? '' ) ) ) ] = trim( (string) preg_replace( '/[\r\n]+/', '', (string) $header['value'] ) );
			}
		}
		$secret   = is_string( $input['secret'] ?? null ) && '' !== trim( $input['secret'] ) ? trim( $input['secret'] ) : null;
		$event_id = wp_generate_uuid4();
		$payload  = Payloads::build(
			$webhook,
			Tree::build( $fields, FieldRegistry::instance() ),
			Payloads::sample( $fields ),
			array(
				'event'    => Payloads::EVENT_TEST,
				'event_id' => $event_id,
				'form'     => array(
					'id'      => $form_id,
					'title'   => $title,
					'version' => 0,
				),
				'entry'    => array(
					'id'        => null,
					'createdAt' => gmdate( 'c' ),
					'sourceUrl' => home_url( '/' ),
				),
			)
		);
		$context  = array(
			'event'         => Payloads::EVENT_TEST,
			'event_id'      => $event_id,
			'attempt'       => 1,
			'header_values' => $headers,
		);
		if ( ! empty( $input['clearSecret'] ) ) {
			$context['secret'] = '';
		} elseif ( null !== $secret ) {
			$context['secret'] = $secret;
		}
		return $this->sender->send( $form_id, $webhook, $payload, $context ) + array( 'payload' => $payload );
	}

	/**
	 * @param array<string, mixed>      $delivery
	 * @param array<string, mixed>|null $webhook
	 * @param array<string, mixed>      $result   Sender result, if a request was made.
	 */
	private function finish( array $delivery, ?array $webhook, string $status, int $attempt, string $message, array $result = array() ): void {
		$this->deliveries->record(
			$delivery['id'],
			array(
				'status'          => $status,
				'attempts'        => $attempt,
				'response_code'   => (int) ( $result['status'] ?? 0 ),
				'message'         => $message,
				'duration_ms'     => (int) ( $result['duration_ms'] ?? 0 ),
				'next_attempt_at' => null,
				// Without a stored entry the data must not outlive the delivery.
				'clear_payload'   => 0 === $delivery['entryId'],
			)
		);
		$delivery = array_merge(
			$delivery,
			array(
				'status'       => $status,
				'attempts'     => $attempt,
				'responseCode' => (int) ( $result['status'] ?? 0 ),
				'message'      => $message,
			)
		);
		if ( DeliveryRepository::SUCCESS === $status ) {
			$this->log( $delivery, 'success', $message );
			/**
			 * Fires after a webhook was delivered (2xx response).
			 *
			 * @since 0.2.0
			 *
			 * @param array<string, mixed> $delivery Delivery (id, formId, entryId, webhookId, eventId, attempts, responseCode ...).
			 * @param array<string, mixed> $webhook  Webhook settings.
			 */
			do_action( 'swf_webhook_sent', $delivery, $webhook );
			return;
		}
		if ( DeliveryRepository::DISMISSED === $status ) {
			$this->log( $delivery, 'skipped', $message );
			return;
		}
		$this->log(
			$delivery,
			'failed',
			$attempt > 1
				/* translators: 1: number of attempts, 2: error */
				? sprintf( __( 'Failed after %1$d attempts: %2$s', 'sw-forms' ), $attempt, $message )
				: $message
		);
		$this->alert( $delivery, $webhook );
		/**
		 * Fires when a webhook delivery failed for good (no further retries).
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, mixed>      $delivery Delivery (status `failed`, message, responseCode ...).
		 * @param array<string, mixed>|null $webhook  Webhook settings.
		 */
		do_action( 'swf_webhook_failed', $delivery, $webhook );
	}

	/**
	 * @param array<string, mixed>      $delivery
	 * @param array<string, mixed>|null $webhook
	 */
	private function alert( array $delivery, ?array $webhook ): void {
		$throttle = 'swf_webhook_alert_' . md5( $delivery['formId'] . ':' . $delivery['webhookId'] );
		if ( false !== get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, self::ALERT_INTERVAL );
		$form = $this->forms->find( (int) $delivery['formId'] );
		$to   = Settings::all()['webhook_alert_email'];
		$to   = '' !== $to ? $to : (string) get_option( 'admin_email' );
		$name = '' !== (string) ( $webhook['name'] ?? '' ) ? (string) $webhook['name'] : NetworkGuard::parseUrl( (string) ( $webhook['url'] ?? '' ) )['host'];
		/* translators: 1: site name, 2: form title */
		$subject = sprintf( __( '[%1$s] Webhook delivery failed: %2$s', 'sw-forms' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ), (string) ( $form['title'] ?? '' ) );
		$lines   = array(
			/* translators: %s: webhook name */
			sprintf( __( 'The webhook "%s" could not be delivered.', 'sw-forms' ), $name ),
			'',
			/* translators: %s: form title */
			sprintf( __( 'Form: %s', 'sw-forms' ), (string) ( $form['title'] ?? '' ) ),
			/* translators: %s: target URL */
			sprintf( __( 'URL: %s', 'sw-forms' ), (string) ( $webhook['url'] ?? '' ) ),
			/* translators: %d: number of attempts */
			sprintf( __( 'Attempts: %d', 'sw-forms' ), (int) $delivery['attempts'] ),
			/* translators: %s: last error */
			sprintf( __( 'Last error: %s', 'sw-forms' ), (string) $delivery['message'] ),
		);
		if ( $delivery['entryId'] > 0 ) {
			/* translators: %d: entry id */
			$lines[] = sprintf( __( 'Entry: #%d', 'sw-forms' ), (int) $delivery['entryId'] );
		}
		$lines[] = '';
		$lines[] = __( 'Further failures of this webhook within the next hour are not reported by e-mail. You can resend failed deliveries in the Webhooks tab of the form:', 'sw-forms' );
		$lines[] = admin_url( 'admin.php?page=sw-forms#/forms/' . (int) $delivery['formId'] . '/webhooks' );
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * @param array<string, mixed> $delivery
	 */
	private function log( array $delivery, string $status, string $message ): void {
		if ( $delivery['entryId'] > 0 ) {
			$this->entries->log( (int) $delivery['entryId'], (int) $delivery['formId'], 'webhook', (string) $delivery['webhookId'], $status, $message );
		}
	}

	/**
	 * Current settings of a webhook (fixes like a corrected URL apply to retries and resends).
	 *
	 * @return array<string, mixed>|null
	 */
	private function webhook( int $form_id, string $webhook_id ): ?array {
		$document = $this->forms->published( $form_id );
		foreach ( $document['settings']['webhooks'] ?? array() as $webhook ) {
			if ( is_array( $webhook ) && ( $webhook['id'] ?? '' ) === $webhook_id ) {
				return WebhookSettings::normalize( $webhook );
			}
		}
		return null;
	}

	private function policy(): RetryPolicy {
		/**
		 * Filters the delays in seconds before each retry of a failed webhook delivery.
		 *
		 * @since 0.2.0
		 *
		 * @param list<int> $delays Default 60, 300, 1800, 7200, 43200 (1 min … 12 h). Empty: no retries.
		 */
		$delays = apply_filters( 'swf_webhook_retry_delays', RetryPolicy::DEFAULT_DELAYS );
		return new RetryPolicy( is_array( $delays ) ? array_values( array_map( 'intval', $delays ) ) : RetryPolicy::DEFAULT_DELAYS );
	}
}
