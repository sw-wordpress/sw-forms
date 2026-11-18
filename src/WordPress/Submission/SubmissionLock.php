<?php
/**
 * Internal per-form database mutex for single-use tokens and rate accounting.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Submission;

final class SubmissionLock {
	private bool $held = true;

	private function __construct( private readonly string $name ) {}

	public static function acquire( int $form_id ): ?self {
		global $wpdb;
		$name = 'swf_submit_' . hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $form_id );
		// MySQL/MariaDB lock names are limited to 64 characters. Connection-scoped locks
		// work across PHP workers and object caches, and disappear if a worker exits.
		$name = substr( $name, 0, 64 );
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cross-worker database mutex.
		return '1' === (string) $held ? new self( $name ) : null;
	}

	public function release(): void {
		if ( ! $this->held ) {
			return;
		}
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- release this connection's mutex.
		$this->held = false;
	}
}
