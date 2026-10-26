<?php
/**
 * Webhook deliveries (table `swf_webhook_deliveries`).
 *
 * Status: `pending` (queued or waiting for a retry), `success`, `failed` (final) and `dismissed`
 * (a failure an administrator acknowledged).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.

use SWForms\WordPress\Database\Schema;

final class DeliveryRepository {

	public const PENDING   = 'pending';
	public const SUCCESS   = 'success';
	public const FAILED    = 'failed';
	public const DISMISSED = 'dismissed';
	public const STATUSES  = array( self::PENDING, self::SUCCESS, self::FAILED, self::DISMISSED );

	/**
	 * @param array<string, mixed> $payload
	 */
	public function insert( int $form_id, int $entry_id, string $webhook_id, string $event, string $event_id, array $payload ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'form_id'         => $form_id,
				'entry_id'        => $entry_id,
				'webhook_id'      => $webhook_id,
				'event'           => $event,
				'event_id'        => $event_id,
				'status'          => self::PENDING,
				'attempts'        => 0,
				'payload'         => (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Records the outcome of an attempt.
	 *
	 * @param array{status: string, attempts: int, response_code: int, message: string, duration_ms: int, next_attempt_at: ?string, clear_payload?: bool} $result
	 */
	public function record( int $id, array $result ): void {
		global $wpdb;
		$data   = array(
			'status'          => $result['status'],
			'attempts'        => $result['attempts'],
			'response_code'   => $result['response_code'],
			'message'         => mb_substr( $result['message'], 0, 2000 ),
			'duration_ms'     => $result['duration_ms'],
			'next_attempt_at' => $result['next_attempt_at'],
			'updated_at'      => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%d', '%d', '%s', '%d', '%s', '%s' );
		if ( ! empty( $result['clear_payload'] ) ) {
			$data['payload'] = null;
			$format[]        = '%s';
		}
		$wpdb->update( self::table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/** Puts a delivery back into the queue (manual resend): the attempt counter starts again. */
	public function requeue( int $id ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->update(
			self::table(),
			array(
				'status'          => self::PENDING,
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'updated_at'      => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( int $form_id, string $status, string $webhook_id, int $page, int $per_page ): array {
		global $wpdb;
		$where = $wpdb->prepare( 'form_id = %d', $form_id );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		if ( '' !== $webhook_id ) {
			$where .= $wpdb->prepare( ' AND webhook_id = %s', $webhook_id );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", self::table() ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", self::table(), $per_page, ( $page - 1 ) * $per_page ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'items' => array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function for_entry( int $entry_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE entry_id = %d ORDER BY id', self::table(), $entry_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Ids of deliveries of a form with the given status (bulk resend / dismiss).
	 *
	 * @return list<int>
	 */
	public function ids( int $form_id, string $status, int $limit = 500 ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE form_id = %d AND status = %s ORDER BY id LIMIT %d', self::table(), $form_id, $status, $limit ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Pending deliveries whose attempt is overdue (queue lost, plugin was deactivated ...).
	 *
	 * @return list<int>
	 */
	public function overdue( int $grace_seconds, int $limit = 200 ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s AND next_attempt_at < %s ORDER BY id LIMIT %d',
				self::table(),
				self::PENDING,
				gmdate( 'Y-m-d H:i:s', time() - $grace_seconds ),
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Failed (unacknowledged) deliveries per form id.
	 *
	 * @return array<int, int>
	 */
	public function failure_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT form_id, COUNT(*) AS n FROM %i WHERE status = %s GROUP BY form_id', self::table(), self::FAILED ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['form_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** Removes final deliveries without entry that are older than the given age (log housekeeping). */
	public function purge_finished( int $days ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE entry_id = 0 AND status IN (%s, %s) AND updated_at < %s',
				self::table(),
				self::SUCCESS,
				self::DISMISSED,
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}

	public static function delete_for_entry( int $entry_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id = %d', self::table(), $entry_id ) );
	}

	public static function delete_for_form( int $form_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id = %d', self::table(), $form_id ) );
	}

	private static function table(): string {
		return Schema::table( 'webhook_deliveries' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$payload = is_string( $row['payload'] ?? null ) ? json_decode( $row['payload'], true ) : null;
		return array(
			'id'            => (int) $row['id'],
			'formId'        => (int) $row['form_id'],
			'entryId'       => (int) $row['entry_id'],
			'webhookId'     => (string) $row['webhook_id'],
			'event'         => (string) $row['event'],
			'eventId'       => (string) $row['event_id'],
			'status'        => (string) $row['status'],
			'attempts'      => (int) $row['attempts'],
			'payload'       => is_array( $payload ) ? $payload : null,
			'payloadJson'   => is_array( $payload ) ? (string) $row['payload'] : null,
			'responseCode'  => (int) $row['response_code'],
			'message'       => (string) $row['message'],
			'durationMs'    => (int) $row['duration_ms'],
			'nextAttemptAt' => null === $row['next_attempt_at'] ? null : mysql_to_rfc3339( (string) $row['next_attempt_at'] ),
			'createdAt'     => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updatedAt'     => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}
}
