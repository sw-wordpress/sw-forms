<?php
/**
 * Submission action and background worker for durable webhook deliveries.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Logic\Evaluator;
use SWForms\Core\Webhooks\RetryPolicy;
use SWForms\WordPress\Actions\ActionInterface;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Submission\Submission;
use SWForms\WordPress\Support\Queue;

final class DeliveryService implements ActionInterface {

	public const HOOK         = 'swf_deliver_webhook';
	public const RECOVER_HOOK = 'swf_recover_webhooks';

	public function __construct(
		private readonly DeliveryRepository $deliveries = new DeliveryRepository(),
		private readonly Transport $transport = new Transport(),
		private readonly Queue $queue = new Queue(),
	) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'deliver' ) );
		add_action( self::RECOVER_HOOK, array( $this, 'recover' ) );
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['swf_five_minutes'] = array(
					'interval' => 300,
					'display'  => __( 'Every five minutes (SW Forms)', 'sw-forms' ),
				);
				return $schedules;
			}
		);
		add_action( 'init', array( $this, 'schedule_recovery' ), 20 );
	}

	public function schedule_recovery(): void {
		if ( ! wp_next_scheduled( self::RECOVER_HOOK ) ) {
			wp_schedule_event( time() + 300, 'swf_five_minutes', self::RECOVER_HOOK );
		}
	}

	public function type(): string {
		return 'webhook';
	}

	/**
	 * @param array<string, mixed> $document Published form.
	 * @param array<string, mixed> $values Collected input.
	 * @return list<array<string, mixed>>
	 */
	public static function eligible( array $document, \SWForms\Core\Form\Tree $tree, array $values ): array {
		$evaluator = new Evaluator( $tree );
		return array_values( array_filter( $document['settings']['webhooks'] ?? array(), static fn ( array $hook ): bool => ! empty( $hook['enabled'] ) && $evaluator->conditionMet( $hook['logic'] ?? null, $values ) ) );
	}

	public function run( Submission $submission ): void {
		$builder = new RequestBuilder();
		foreach ( self::eligible( $submission->document, $submission->tree, $submission->values ) as $hook ) {
			try {
				$event_id       = wp_generate_uuid4();
				$request        = $builder->build( $builder->payload( $submission, $hook, $event_id ), $hook );
				$request['url'] = $hook['url'];
				$id             = $this->deliveries->insert( $submission->form_id(), $submission->entry_id, $hook['id'], $event_id, $request );
				$submission->log( 'webhook', $hook['id'], 'queued', __( 'Webhook queued for delivery.', 'sw-forms' ) );
				// Persist first. A queue failure is repaired by recover(), not by losing the event.
				$this->enqueue( $id );
			} catch ( \Throwable $error ) {
				$submission->log( 'webhook', $hook['id'], 'failed', __( 'Webhook could not be prepared. Check its signing secret and configuration.', 'sw-forms' ) );
				$this->notify_failure( $submission->form_id(), 0 );
			}
		}
	}

	private function enqueue( int $id, int $timestamp = 0 ): void {
		try {
			$this->queue->schedule( self::HOOK, array( $id ), $timestamp );
		} catch ( \Throwable $error ) {
			// The encrypted pending row is the durable outbox; recovery will schedule it again.
			return;
		}
	}

	public function deliver( int $id ): void {
		$row = $this->deliveries->claim( $id );
		if ( null === $row ) {
			return;
		}
		$entries  = new EntryRepository();
		$entry_id = (int) $row['entry_id'];
		if ( null === $entries->find( $entry_id ) ) {
			$this->deliveries->delete_for_entry( $entry_id );
			return;
		}
		$attempt = (int) $row['attempts'];
		try {
			$request = json_decode( SecretVault::decrypt( $row['payload'] ), true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $request ) || $attempt > RetryPolicy::MAX_ATTEMPTS ) {
				throw new \RuntimeException( 'Delivery attempts exhausted or snapshot invalid.' );
			}
			$result = $this->transport->send( $request );
		} catch ( \Throwable $error ) {
			$result = array(
				'success'  => false,
				'code'     => 0,
				'duration' => 0,
				'message'  => 'Delivery snapshot unavailable or attempts exhausted.',
			);
		}
		$delay = $result['success'] ? null : RetryPolicy::delay( $attempt );
		if ( ! $this->deliveries->finish( $row, $result, $delay ) ) {
			return;
		}
		$status = $result['success'] ? 'success' : ( null === $delay ? 'failed' : 'retry' );
		$entries->log(
			$entry_id,
			(int) $row['form_id'],
			'webhook',
			$row['webhook_id'],
			$status,
			sprintf(
				/* translators: 1: delivery id, 2: attempt number, 3: HTTP status, 4: milliseconds, 5: response excerpt */
				__( 'Delivery #%1$d, attempt %2$d, HTTP %3$d, %4$d ms: %5$s', 'sw-forms' ),
				$id,
				$attempt,
				$result['code'],
				$result['duration'],
				$result['message']
			)
		);
		if ( null !== $delay ) {
			$this->enqueue( $id, time() + $delay );
		} else {
			$summary = array();
			foreach ( $this->deliveries->for_entry( $entry_id ) as $delivery ) {
				if ( $delivery['id'] === $id ) {
					$summary = $delivery;
					break;
				}
			}
			try {
				if ( $result['success'] ) {
					/**
					 * Fires after successful delivery.
					 *
					 * @since 0.2.0
					 * @param int $delivery_id Delivery identifier.
					 * @param array<string, mixed> $delivery Redacted status metadata, no payload/secrets.
					 */
					do_action( 'swf_webhook_sent', $id, $summary );
				} else {
					$this->notify_failure( (int) $row['form_id'], $id );
					/**
					 * Fires after final unsuccessful delivery.
					 *
					 * @since 0.2.0
					 * @param int $delivery_id Delivery identifier.
					 * @param array<string, mixed> $delivery Redacted status metadata, no payload/secrets.
					 */
					do_action( 'swf_webhook_failed', $id, $summary );
				}
			} finally {
				$this->cleanup_entry( $entry_id );
			}
		}
	}

	public function recover(): void {
		foreach ( $this->deliveries->recoverable() as $id ) {
			if ( ! as_has_scheduled_action( self::HOOK, array( $id ), Queue::GROUP ) ) {
				$this->enqueue( $id );
			}
		}
		foreach ( $this->deliveries->temporary_cleanup_candidates() as $entry_id ) {
			$this->cleanup_entry( $entry_id );
		}
		$this->deliveries->purge_detached();
	}

	public function cleanup_entry( int $entry_id ): void {
		$entries = new EntryRepository();
		$entry   = $entries->find( $entry_id );
		if ( null !== $entry && 'temporary' === $entry['status'] && ! $this->deliveries->pending_for_entry( $entry_id ) ) {
			$this->deliveries->detach_temporary( $entry_id );
			$entries->delete( $entry_id );
		}
	}

	private function notify_failure( int $form_id, int $delivery_id ): void {
		wp_mail(
			(string) get_option( 'admin_email' ),
			__( 'SW Forms: webhook delivery failed', 'sw-forms' ),
			sprintf(
				/* translators: 1: form id, 2: delivery id */
				__( 'Webhook delivery failed for form #%1$d (delivery #%2$d). Open SW Forms to review its configuration and entry log.', 'sw-forms' ),
				$form_id,
				$delivery_id
			)
		);
	}
}
