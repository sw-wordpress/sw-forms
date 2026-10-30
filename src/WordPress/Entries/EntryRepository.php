<?php
/**
 * Entries, their search index (entry_values) and the action log.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Entries;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.

use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Uploads\UploadStorage;

final class EntryRepository {

	public const STATUSES = array( 'unread', 'read', 'spam', 'trash' );
	/** List filters: inbox = unread + read. */
	public const FILTERS = array( 'inbox', 'unread', 'starred', 'spam', 'trash' );

	/**
	 * @param array<string, mixed>                                   $data
	 * @param array<string, mixed>                                   $meta
	 * @param list<array{field: string, row: string, value: string}> $values
	 */
	public function insert( int $form_id, int $version, array $data, array $meta, array $values, string $ip_hash, int $user_id, bool $temporary = false ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		$ok       = $wpdb->insert(
			Schema::table( 'entries' ),
			array(
				'form_id'      => $form_id,
				'form_version' => $version,
				'status'       => $temporary ? 'temporary' : 'unread',
				'starred'      => 0,
				'data'         => (string) wp_json_encode( $data ),
				'meta'         => (string) wp_json_encode( $meta ),
				'ip_hash'      => $ip_hash,
				'user_id'      => $user_id,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$entry_id = (int) $wpdb->insert_id;
		foreach ( $values as $value ) {
			if ( false === $ok ) {
				break;
			}
			$ok = $wpdb->insert(
				Schema::table( 'entry_values' ),
				array(
					'entry_id' => $entry_id,
					'form_id'  => $form_id,
					'field_id' => $value['field'],
					'row_path' => $value['row'],
					'value'    => $value['value'],
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
		}
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		$wpdb->query( 'COMMIT' );
		return $entry_id;
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( int $form_id, string $filter, string $search, int $page, int $per_page, string $order ): array {
		global $wpdb;
		$entries = Schema::table( 'entries' );
		$where   = $wpdb->prepare( 'e.form_id = %d', $form_id );
		switch ( $filter ) {
			case 'unread':
				$where .= " AND e.status = 'unread'";
				break;
			case 'starred':
				$where .= " AND e.starred = 1 AND e.status IN ('unread','read')";
				break;
			case 'spam':
			case 'trash':
				$where .= $wpdb->prepare( ' AND e.status = %s', $filter );
				break;
			default:
				$where .= " AND e.status IN ('unread','read')";
		}
		if ( '' !== $search ) {
			$where .= $wpdb->prepare(
				' AND e.id IN (SELECT v.entry_id FROM %i v WHERE v.form_id = %d AND v.value LIKE %s)',
				Schema::table( 'entry_values' ),
				$form_id,
				'%' . $wpdb->esc_like( $search ) . '%'
			);
		}
		$direction = 'asc' === $order ? 'ASC' : 'DESC';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments, $direction is an allowlist.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e WHERE $where", $entries ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT e.* FROM %i e WHERE $where ORDER BY e.id $direction LIMIT %d OFFSET %d", $entries, $per_page, ( $page - 1 ) * $per_page ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = array();
		$ids   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$item                 = self::hydrate( $row );
			$item['values']       = array();
			$items[ $item['id'] ] = $item;
			$ids[]                = $item['id'];
		}
		if ( array() !== $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated list of %d.
			$values = $wpdb->get_results(
				$wpdb->prepare( "SELECT entry_id, field_id, value FROM %i WHERE row_path = '' AND entry_id IN ($placeholders)", Schema::table( 'entry_values' ), ...$ids ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			foreach ( is_array( $values ) ? $values : array() as $value ) {
				$items[ (int) $value['entry_id'] ]['values'][ (string) $value['field_id'] ] = mb_substr( (string) $value['value'], 0, 300 );
			}
		}
		return array(
			'items' => array_values( $items ),
			'total' => $total,
		);
	}

	/**
	 * @return array<string, int> Counters per list filter.
	 */
	public function counts( int $form_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(status IN ('unread','read')) AS inbox, SUM(status = 'unread') AS unread,
					SUM(starred = 1 AND status IN ('unread','read')) AS starred, SUM(status = 'spam') AS spam, SUM(status = 'trash') AS trash
				FROM %i WHERE form_id = %d",
				Schema::table( 'entries' ),
				$form_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( self::FILTERS as $filter ) {
			$out[ $filter ] = (int) ( is_array( $row ) ? ( $row[ $filter ] ?? 0 ) : 0 );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'entries' ), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/** @return array<string, mixed>|null Public entry access excludes temporary queue storage. */
	public function find_visible( int $id ): ?array {
		$entry = $this->find( $id );
		return null !== $entry && 'temporary' !== $entry['status'] ? $entry : null;
	}

	/**
	 * @param array{status?: string, starred?: bool} $changes
	 */
	public function update( int $id, array $changes ): void {
		global $wpdb;
		$data   = array();
		$format = array();
		if ( isset( $changes['status'] ) && in_array( $changes['status'], self::STATUSES, true ) ) {
			$data['status'] = $changes['status'];
			$format[]       = '%s';
		}
		if ( isset( $changes['starred'] ) ) {
			$data['starred'] = $changes['starred'] ? 1 : 0;
			$format[]        = '%d';
		}
		if ( array() === $data ) {
			return;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$format[]           = '%s';
		$wpdb->update( Schema::table( 'entries' ), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	public function delete( int $id ): void {
		global $wpdb;
		( new \SWForms\WordPress\Webhooks\DeliveryRepository() )->delete_for_entry( $id );
		$entry = $this->find( $id );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id = %d', Schema::table( 'entry_values' ), $id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id = %d', Schema::table( 'entry_log' ), $id ) );
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'entries' ), $id ) );
		if ( false !== $deleted ) {
			UploadStorage::delete( is_array( $entry['meta']['uploads'] ?? null ) ? $entry['meta']['uploads'] : array() );
		}
	}

	/** @param array<string, mixed>                                   $data
	 * @param list<array{field: string, row: string, value: string}> $values
	 */
	public function replace_data( int $id, array $data, array $values, string $revision ): bool {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$old = $wpdb->get_row( $wpdb->prepare( 'SELECT data, status, form_id FROM %i WHERE id = %d FOR UPDATE', Schema::table( 'entries' ), $id ), ARRAY_A );
		if ( ! is_array( $old ) || 'temporary' === $old['status'] || ! hash_equals( EntryEditor::revision( (array) json_decode( $old['data'], true ) ), $revision ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$ok = $wpdb->update(
			Schema::table( 'entries' ),
			array(
				'data'       => (string) wp_json_encode( $data ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		$ok = false !== $ok && false !== $wpdb->delete( Schema::table( 'entry_values' ), array( 'entry_id' => $id ) );
		foreach ( $values as $value ) {
			if ( ! $ok ) {
				break; }
			$ok = false !== $wpdb->insert(
				Schema::table( 'entry_values' ),
				array(
					'entry_id' => $id,
					'form_id'  => $old['form_id'],
					'field_id' => $value['field'],
					'row_path' => $value['row'],
					'value'    => $value['value'],
				)
			);
		}
		if ( $ok ) {
			$wpdb->query( 'COMMIT' );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
		return $ok;
	}

	/** @return list<array<string, mixed>> Internal privacy pagination includes temporary/trash/spam entries. */
	public function privacy_batch( int $after ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id LIMIT 100', Schema::table( 'entries' ), $after ), ARRAY_A );
		return array_map( self::hydrate( ... ), is_array( $rows ) ? $rows : array() );
	}

	/** Deletes entries of a form older than the retention period. Returns the number of deleted entries. */
	public function purge_older_than( int $form_id, int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids    = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM %i WHERE form_id = %d AND status <> 'temporary' AND created_at < %s LIMIT 500", Schema::table( 'entries' ), $form_id, $cutoff )
		);
		foreach ( $ids as $id ) {
			$this->delete( (int) $id );
		}
		return count( $ids );
	}

	public function log( int $entry_id, int $form_id, string $type, string $action_id, string $status, string $message ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'entry_log' ),
			array(
				'entry_id'    => $entry_id,
				'form_id'     => $form_id,
				'action_type' => $type,
				'action_id'   => $action_id,
				'status'      => $status,
				'message'     => mb_substr( $message, 0, 2000 ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function logs( int $entry_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT action_type, action_id, status, message, created_at FROM %i WHERE entry_id = %d ORDER BY id', Schema::table( 'entry_log' ), $entry_id ),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'type'      => (string) $row['action_type'],
				'actionId'  => (string) $row['action_id'],
				'status'    => (string) $row['status'],
				'message'   => (string) $row['message'],
				'createdAt' => mysql_to_rfc3339( (string) $row['created_at'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$data = json_decode( (string) $row['data'], true );
		$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
		return array(
			'id'          => (int) $row['id'],
			'formId'      => (int) $row['form_id'],
			'formVersion' => (int) $row['form_version'],
			'status'      => (string) $row['status'],
			'starred'     => (bool) $row['starred'],
			'data'        => is_array( $data ) ? $data : array(),
			'meta'        => is_array( $meta ) ? $meta : array(),
			'userId'      => (int) $row['user_id'],
			'createdAt'   => mysql_to_rfc3339( (string) $row['created_at'] ),
		);
	}
}
