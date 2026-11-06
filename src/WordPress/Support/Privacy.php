<?php
/**
 * WordPress personal-data tools: match email-typed fields in each entry's own version.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Forms\FormRepository;

final class Privacy {

	public function register(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( array $items ): array {
				$items['sw-forms'] = array(
					'exporter_friendly_name' => 'SW Forms',
					'callback'               => array( $this, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( array $items ): array {
				$items['sw-forms'] = array(
					'eraser_friendly_name' => 'SW Forms',
					'callback'             => array( $this, 'erase' ),
				);
				return $items;
			}
		);
	}

	/** @return array<string, mixed> */
	public function export( string $email, int $page = 1 ): array {
		return $this->process( $email, $page, false );
	}

	/** @return array<string, mixed> */
	public function erase( string $email, int $page = 1 ): array {
		return $this->process( $email, $page, true );
	}

	/** @return array<string, mixed> */
	private function process( string $email, int $page, bool $erase ): array {
		if ( ! is_email( trim( $email ) ) ) {
			return $erase ? array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			) : array(
				'data' => array(),
				'done' => true,
			);
		}
		$key     = 'swf_privacy_' . hash_hmac( 'sha256', strtolower( trim( $email ) ) . ':' . (int) $erase . ':' . get_current_user_id(), Settings::secret() );
		$cursors = $page <= 1 ? array( 1 => 0 ) : get_transient( $key );
		$cursors = is_array( $cursors ) ? $cursors : array( 1 => 0 );
		$cursor  = (int) ( $cursors[ $page ] ?? 0 );
		$repo    = new EntryRepository();
		$forms   = new FormRepository();
		$rows    = $repo->privacy_batch( $cursor );
		$items   = array();
		$removed = false;
		foreach ( $rows as $entry ) {
			$cursor = $entry['id'];
			$doc    = $forms->version( $entry['formId'], $entry['formVersion'] ) ?? array();
			if ( ! self::matches( $doc['fields'] ?? array(), $entry['data'], $email ) ) {
				continue; }
			if ( $erase ) {
				$repo->delete( $entry['id'] );
				$removed = true;
				continue; }
			$notes   = array_filter( $repo->logs( $entry['id'] ), static fn ( array $log ): bool => 'note' === $log['type'] );
			$items[] = array(
				'group_id'          => 'sw-forms',
				'group_label'       => __( 'Form entries', 'sw-forms' ),
				'group_description' => __( 'Entries submitted through SW Forms.', 'sw-forms' ),
				'item_id'           => 'swf-entry-' . $entry['id'],
				'data'              => array(
					array(
						'name'  => __( 'Entry', 'sw-forms' ),
						'value' => (string) $entry['id'],
					),
					array(
						'name'  => __( 'Form', 'sw-forms' ),
						'value' => (string) $entry['formId'],
					),
					array(
						'name'  => __( 'Date', 'sw-forms' ),
						'value' => $entry['createdAt'],
					),
					array(
						'name'  => __( 'Values', 'sw-forms' ),
						'value' => (string) wp_json_encode( $entry['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					),
					array(
						'name'  => __( 'Notes', 'sw-forms' ),
						'value' => implode( "\n", array_column( $notes, 'message' ) ),
					),
				),
			);
		}
		$done = count( $rows ) < 100;
		if ( $done ) {
			delete_transient( $key );
		} else {
			$cursors[ $page + 1 ] = $cursor;
			set_transient( $key, $cursors, DAY_IN_SECONDS ); }
		return $erase ? array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		) : array(
			'data' => $items,
			'done' => $done,
		);
	}

	/** @param list<array<string, mixed>> $fields
	 * @param array<string, mixed>       $data
	 */
	public static function matches( array $fields, array $data, string $email ): bool {
		foreach ( $fields as $field ) {
			foreach ( $field['columns'] ?? array() as $column ) {
				if ( self::matches( $column['children'], $data, $email ) ) {
					return true; }
			}
			$key   = $field['key'] ?? '';
			$value = $data[ $key ] ?? null;
			if ( 'input' === $field['type'] && 'email' === ( $field['settings']['inputType'] ?? '' ) && is_string( $value ) && 0 === strcasecmp( trim( $value ), trim( $email ) ) ) {
				return true; }
			if ( isset( $field['children'] ) && is_array( $value ) ) {
				$rows = ! empty( $field['settings']['repeatable'] ) ? $value : array( $value );
				foreach ( $rows as $row ) {
					if ( is_array( $row ) && self::matches( $field['children'], $row, $email ) ) {
						return true; }
				}
			}
		}
		return false;
	}
}
