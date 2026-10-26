<?php
/**
 * Activation, deactivation and uninstall handling.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress;

use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Queue\Queue;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Support\Maintenance;
use SWForms\WordPress\Support\Settings;
use SWForms\WordPress\Webhooks\DeliveryService;
use SWForms\WordPress\Webhooks\SecretStore;

final class Lifecycle {

	public static function activate(): void {
		Schema::migrate();
		Capabilities::grant_defaults();
		Maintenance::schedule();
		// Deliveries that were due while the plugin was inactive (Action Scheduler is ready by then).
		if ( false === wp_next_scheduled( DeliveryService::RECOVER_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, DeliveryService::RECOVER_HOOK );
		}
	}

	/** Deactivation keeps all data, queued webhook deliveries continue after reactivation. */
	public static function deactivate(): void {
		Maintenance::unschedule();
	}

	/** Removes plugin data only if enabled in the settings. */
	public static function uninstall(): void {
		Maintenance::unschedule();
		Queue::cancel( DeliveryService::HOOK );
		if ( ! Settings::all()['delete_data_on_uninstall'] ) {
			return;
		}
		global $wpdb;
		$forms = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', Schema::table( 'forms' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall.
		foreach ( is_array( $forms ) ? $forms : array() as $form_id ) {
			( new SecretStore() )->delete( (int) $form_id );
		}
		Schema::drop_all();
		Capabilities::revoke_all();
		delete_option( Settings::OPTION );
		delete_option( Schema::VERSION_OPTION );
	}
}
