<?php
/**
 * Forms and their published versions.
 *
 * A form has an optional draft (autosave of the builder, used by the preview) and immutable
 * published versions. Entries reference the version they were submitted against.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Forms;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables; reads are cheap and per request.

use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Webhooks\SecretStore;

final class FormRepository {

	public const STATUSES = array( 'active', 'inactive' );

	/**
	 * @param array<string, mixed> $document
	 */
	public function create( string $title, array $document ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Schema::table( 'forms' ),
			array(
				'title'           => $title,
				'status'          => 'active',
				'draft'           => (string) wp_json_encode( $document ),
				'current_version' => 0,
				'created_by'      => get_current_user_id(),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{id: int, title: string, status: string, draft: array<string, mixed>|null, current_version: int, created_at: string, updated_at: string}|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'forms' ), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Forms with entry counters (inbox = unread + read).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.title, f.status, f.current_version, f.created_at, f.updated_at, (f.draft IS NOT NULL) AS has_draft,
					SUM(e.status IN ('unread','read')) AS entries, SUM(e.status = 'unread') AS unread
				FROM %i f LEFT JOIN %i e ON e.form_id = f.id
				GROUP BY f.id ORDER BY f.updated_at DESC",
				Schema::table( 'forms' ),
				Schema::table( 'entries' )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'             => (int) $row['id'],
				'title'          => (string) $row['title'],
				'status'         => (string) $row['status'],
				'currentVersion' => (int) $row['current_version'],
				'hasDraft'       => (bool) $row['has_draft'],
				'entries'        => (int) $row['entries'],
				'unread'         => (int) $row['unread'],
				'createdAt'      => mysql_to_rfc3339( (string) $row['created_at'] ),
				'updatedAt'      => mysql_to_rfc3339( (string) $row['updated_at'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	public function save_draft( int $id, array $document ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'forms' ),
			array(
				'draft'      => (string) wp_json_encode( $document ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Publishes a document. A new version is only created when it differs from the current one.
	 *
	 * @param array<string, mixed> $document
	 * @return int The current version after publishing.
	 */
	public function publish( int $id, string $title, array $document ): int {
		global $wpdb;
		$form = $this->find( $id );
		if ( null === $form ) {
			return 0;
		}
		$json    = (string) wp_json_encode( $document );
		$current = $form['current_version'];
		$now     = current_time( 'mysql', true );
		if ( 0 === $current || $this->version_json( $id, $current ) !== $json ) {
			++$current;
			$wpdb->insert(
				Schema::table( 'form_versions' ),
				array(
					'form_id'    => $id,
					'version'    => $current,
					'document'   => $json,
					'created_by' => get_current_user_id(),
					'created_at' => $now,
				),
				array( '%d', '%d', '%s', '%d', '%s' )
			);
		}
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET title = %s, draft = NULL, current_version = %d, updated_at = %s WHERE id = %d',
				Schema::table( 'forms' ),
				$title,
				$current,
				$now,
				$id
			)
		);
		return $current;
	}

	public function update_status( int $id, string $status ): void {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$wpdb->update(
			Schema::table( 'forms' ),
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
	 * @return array<string, mixed>|null
	 */
	public function version( int $form_id, int $version ): ?array {
		$json = $this->version_json( $form_id, $version );
		if ( null === $json ) {
			return null;
		}
		$document = json_decode( $json, true );
		return is_array( $document ) ? $document : null;
	}

	/**
	 * The published document, or null when the form was never published.
	 *
	 * @return array<string, mixed>|null
	 */
	public function published( int $form_id ): ?array {
		$form = $this->find( $form_id );
		return null === $form || 0 === $form['current_version'] ? null : $this->version( $form_id, $form['current_version'] );
	}

	private function version_json( int $form_id, int $version ): ?string {
		global $wpdb;
		$json = $wpdb->get_var(
			$wpdb->prepare( 'SELECT document FROM %i WHERE form_id = %d AND version = %d', Schema::table( 'form_versions' ), $form_id, $version )
		);
		return is_string( $json ) ? $json : null;
	}

	/** Deletes the form with all versions, entries, logs, webhook deliveries and secrets. */
	public function delete( int $id ): void {
		global $wpdb;
		( new SecretStore() )->delete( $id );
		foreach ( array( 'entry_values', 'entry_log', 'entries', 'form_versions', 'webhook_deliveries' ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id = %d', Schema::table( $table ), $id ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'forms' ), $id ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: int, title: string, status: string, draft: array<string, mixed>|null, current_version: int, created_at: string, updated_at: string}
	 */
	private static function hydrate( array $row ): array {
		$draft = is_string( $row['draft'] ?? null ) ? json_decode( $row['draft'], true ) : null;
		return array(
			'id'              => (int) $row['id'],
			'title'           => (string) $row['title'],
			'status'          => (string) $row['status'],
			'draft'           => is_array( $draft ) ? $draft : null,
			'current_version' => (int) $row['current_version'],
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
	}
}
