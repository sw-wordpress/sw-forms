<?php
/**
 * Durable encrypted request snapshots and delivery leases.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Support\Queue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- owned custom tables.
final class DeliveryRepository {

	/**
	 * @param array<string, mixed> $request Frozen HTTP request including URL.
	 * @throws \RuntimeException If the snapshot cannot be persisted.
	 */
	public function insert( int $form_id, int $entry_id, string $webhook_id, string $event_id, array $request ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			Schema::table( 'webhook_deliveries' ),
			array(
				'form_id'         => $form_id,
				'entry_id'        => $entry_id,
				'webhook_id'      => $webhook_id,
				'event'           => 'entry.created',
				'event_id'        => $event_id,
				'status'          => 'pending',
				'attempts'        => 0,
				'payload'         => SecretVault::encrypt( (string) wp_json_encode( $request ) ),
				'payload_version' => 1,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Webhook delivery could not be persisted.' );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND payload_version = 1', Schema::table( 'webhook_deliveries' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string, mixed>|null */
	public function claim( int $id ): ?array {
		global $wpdb;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$token = wp_generate_uuid4();
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'running', attempts = attempts + 1, lease_token = %s, lease_until = %s, updated_at = %s WHERE id = %d AND payload_version = 1 AND status = 'pending' AND next_attempt_at <= %s",
				Schema::table( 'webhook_deliveries' ),
				$token,
				gmdate( 'Y-m-d H:i:s', time() + 120 ),
				$now,
				$id,
				$now
			)
		);
		return 1 === $ok ? $this->find( $id ) : null;
	}

	/**
	 * Compare the lease so a stale worker cannot overwrite a later attempt.
	 *
	 * @param array<string, mixed>                                            $row Claimed row.
	 * @param array{success: bool, code: int, duration: int, message: string} $result Outcome.
	 */
	public function finish( array $row, array $result, ?int $delay ): bool {
		global $wpdb;
		return 1 === $wpdb->update(
			Schema::table( 'webhook_deliveries' ),
			array(
				'status'          => $result['success'] ? 'success' : ( null === $delay ? 'failed' : 'pending' ),
				'response_code'   => $result['code'],
				'message'         => $result['message'],
				'duration_ms'     => $result['duration'],
				'next_attempt_at' => null === $delay ? null : gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'lease_until'     => null,
				'lease_token'     => '',
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'          => (int) $row['id'],
				'lease_token' => $row['lease_token'],
				'status'      => 'running',
			)
		);
	}

	/** @return list<int> Expired leases and due pending rows; scheduling duplicates is harmless. */
	public function recoverable(): array {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending', next_attempt_at = %s, lease_token = '', lease_until = NULL WHERE payload_version = 1 AND status = 'running' AND lease_until < %s", Schema::table( 'webhook_deliveries' ), $now, $now ) );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE payload_version = 1 AND status = 'pending' AND next_attempt_at <= %s ORDER BY id LIMIT 100", Schema::table( 'webhook_deliveries' ), $now ) ) );
	}

	public function pending_for_entry( int $entry_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE entry_id = %d AND payload_version = 1 AND status IN ('pending','running') LIMIT 1", Schema::table( 'webhook_deliveries' ), $entry_id ) );
	}

	/** @return list<array<string, mixed>> Public metadata only. */
	public function for_entry( int $entry_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, webhook_id, event_id, status, attempts, response_code, message, duration_ms, next_attempt_at FROM %i WHERE entry_id = %d AND payload_version = 1 ORDER BY id', Schema::table( 'webhook_deliveries' ), $entry_id ), ARRAY_A );
		return array_map(
			static fn ( array $row ): array => array(
				'id'            => (int) $row['id'],
				'webhookId'     => $row['webhook_id'],
				'eventId'       => $row['event_id'],
				'status'        => $row['status'],
				'attempts'      => (int) $row['attempts'],
				'httpCode'      => (int) $row['response_code'],
				'message'       => (string) $row['message'],
				'durationMs'    => (int) $row['duration_ms'],
				'nextAttemptAt' => $row['next_attempt_at'] ? mysql_to_rfc3339( $row['next_attempt_at'] ) : null,
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	public function resend( int $id, int $entry_id ): bool {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending', attempts = 0, next_attempt_at = %s WHERE id = %d AND entry_id = %d AND payload_version = 1 AND status IN ('success','failed') AND payload <> ''", Schema::table( 'webhook_deliveries' ), gmdate( 'Y-m-d H:i:s' ), $id, $entry_id ) );
	}

	/** Remove all personal data before deleting a temporary entry; retain failure counters. */
	public function detach_temporary( int $entry_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET entry_id = 0, event_id = '', payload = '', message = '' WHERE entry_id = %d AND payload_version = 1", Schema::table( 'webhook_deliveries' ), $entry_id ) );
	}

	/** @return array<int, int> Form id => failed delivery count. */
	public function failure_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT form_id, COUNT(*) AS total FROM %i WHERE payload_version = 1 AND status = 'failed' GROUP BY form_id", Schema::table( 'webhook_deliveries' ) ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['form_id'] ] = (int) $row['total'];
		}
		return $out;
	}

	public function delete_for_entry( int $entry_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE entry_id = %d', Schema::table( 'webhook_deliveries' ), $entry_id ) );
		foreach ( $ids as $id ) {
			if ( class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
				( new Queue() )->cancel( DeliveryService::HOOK, array( (int) $id ) );
			}
		}
		$wpdb->delete( Schema::table( 'webhook_deliveries' ), array( 'entry_id' => $entry_id ) );
	}

	public function delete_for_form( int $form_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'webhook_deliveries' ), array( 'form_id' => $form_id ) );
	}

	/** @return list<int> Old temporary rows left by interrupted submissions. */
	public function temporary_cleanup_candidates(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'temporary' AND created_at < %s LIMIT 100", Schema::table( 'entries' ), gmdate( 'Y-m-d H:i:s', time() - 600 ) ) ) );
	}

	public function purge_detached(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id = 0 AND payload_version = 1 AND created_at < %s', Schema::table( 'webhook_deliveries' ), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
	}
}
