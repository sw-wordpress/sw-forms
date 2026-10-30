<?php
/**
 * Plugin bootstrap: wires WordPress hooks. Business logic lives in services.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress;

use SWForms\WordPress\Admin\AdminPage;
use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Frontend\Assets;
use SWForms\WordPress\Frontend\Postback;
use SWForms\WordPress\Frontend\Preview;
use SWForms\WordPress\Frontend\Shortcode;
use SWForms\WordPress\Frontend\TokenEndpoint;
use SWForms\WordPress\Rest\EntriesController;
use SWForms\WordPress\Rest\FormsController;
use SWForms\WordPress\Rest\SettingsController;
use SWForms\WordPress\Rest\SubmitController;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Support\Maintenance;
use SWForms\WordPress\Support\Wpml;

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted             = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_migrate' ), 5 );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		( new Shortcode() )->register();
		( new Assets() )->register();
		( new Postback() )->register();
		( new Preview() )->register();
		( new TokenEndpoint() )->register();
		( new Maintenance() )->register();
		( new \SWForms\WordPress\Support\Privacy() )->register();
		( new \SWForms\WordPress\Webhooks\DeliveryService() )->register();
		( new Wpml() )->register();

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}

	/**
	 * Loads the translations. Regional and formal variants (de_AT, de_CH, de_DE_formal ...) use the
	 * shipped German catalog: since WordPress 6.7 translations are loaded just in time by exact
	 * locale file name, so a `load_textdomain_mofile` fallback would never be reached.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'sw-forms', false, dirname( plugin_basename( SWF_FILE ) ) . '/languages' );
		$locale = determine_locale();
		if ( 'de_DE' !== $locale && str_starts_with( $locale, 'de' ) && ! is_readable( SWF_DIR . 'languages/sw-forms-' . $locale . '.mo' ) ) {
			load_textdomain( 'sw-forms', SWF_DIR . 'languages/sw-forms-de_DE.mo', $locale );
		}
	}

	/** Cheap option comparison; migrations are idempotent. */
	public function maybe_migrate(): void {
		if ( Schema::needs_migration() ) {
			Schema::migrate();
			Capabilities::grant_defaults();
			Maintenance::schedule();
		}
	}

	public function register_rest(): void {
		foreach ( array( new FormsController(), new EntriesController(), new SubmitController(), new SettingsController(), new \SWForms\WordPress\Rest\WebhooksController(), new \SWForms\WordPress\Rest\ExportController(), new \SWForms\WordPress\Rest\EntryEditingController() ) as $controller ) {
			$controller->register_routes();
		}
	}
}
