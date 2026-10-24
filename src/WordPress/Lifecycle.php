<?php
/**
 * Activation, deactivation and uninstall handling.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress;

use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Support\Maintenance;
use SWForms\WordPress\Support\Settings;

final class Lifecycle {

	public static function activate(): void {
		Schema::migrate();
		Capabilities::grant_defaults();
		Maintenance::schedule();
	}

	/** Deactivation keeps all data. */
	public static function deactivate(): void {
		Maintenance::unschedule();
	}

	/** Removes plugin data only if enabled in the settings. */
	public static function uninstall(): void {
		Maintenance::unschedule();
		if ( ! Settings::all()['delete_data_on_uninstall'] ) {
			return;
		}
		Schema::drop_all();
		Capabilities::revoke_all();
		delete_option( Settings::OPTION );
		delete_option( Schema::VERSION_OPTION );
	}
}
