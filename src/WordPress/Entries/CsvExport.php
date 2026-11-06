<?php
/**
 * Streams the same filtered entry set as the list; nested repeaters stay JSON.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Entries;

use SWForms\Core\Export\Csv;

final class CsvExport {

	/** @param resource $stream */
	public function write( $stream, int $form_id, string $filter, string $search, string $order = 'desc' ): void {
		$entries = new EntryRepository();
		$columns = array();
		$total   = 0;
		// Discover a union of keys, including older versions and fields supplied by data filters.
		for ( $page = 1; ; ++$page ) {
			$batch = $entries->search( $form_id, $filter, $search, $page, 200, $order );
			$total = $batch['total'];
			foreach ( $batch['items'] as $entry ) {
				foreach ( array_keys( Csv::flatten( $entry['data'] ) ) as $key ) {
					$columns[ $key ] = true;
				}
			}
			if ( $page * 200 >= $total ) {
				break;
			}
		}
		$keys = array_keys( $columns );
		Csv::row( $stream, array_merge( array( 'entry_id', 'created_at', 'status' ), $keys ) );
		for ( $page = 1; $page <= max( 1, (int) ceil( $total / 200 ) ); ++$page ) {
			foreach ( $entries->search( $form_id, $filter, $search, $page, 200, $order )['items'] as $entry ) {
				$data = Csv::flatten( $entry['data'] );
				$row  = array( (string) $entry['id'], $entry['createdAt'], $entry['status'] );
				foreach ( $keys as $key ) {
					$row[] = $data[ $key ] ?? '';
				}
				Csv::row( $stream, $row );
			}
		}
	}
}
