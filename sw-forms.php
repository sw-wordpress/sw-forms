<?php
/**
 * Plugin Name:       SW Forms
 * Description:       Drag and drop form builder with conditional logic, entries, notifications and an extension API.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Stefan Warnat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sw-forms
 * Domain Path:       /languages
 *
 * @package SWForms
 */

defined( 'ABSPATH' ) || exit;

define( 'SWF_VERSION', '0.1.0' );
define( 'SWF_FILE', __FILE__ );
define( 'SWF_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWF_URL', plugin_dir_url( __FILE__ ) );
define( 'SWF_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, SWF_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'SW Forms requires PHP %1$s or newer. You are running PHP %2$s.', 'sw-forms' ),
						SWF_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

$swf_autoload = SWF_DIR . 'vendor-prefixed/autoload.php';
if ( ! is_readable( $swf_autoload ) ) {
	// Source checkouts can use Composer directly; release ZIPs only ship Mozart's loader.
	$swf_autoload = SWF_DIR . 'vendor/autoload.php';
}
if ( ! is_readable( $swf_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'SW Forms: the vendor directory is missing. Install the release package or run "composer install".', 'sw-forms' )
			);
		}
	);
	return;
}
$swf_loader = require $swf_autoload;
// Mozart's generated loader knows the dependencies; add the plugin's own code too.
$swf_loader->addPsr4( 'SWForms\\', SWF_DIR . 'src/' );
unset( $swf_autoload, $swf_loader );

// AS coordinates versions across plugins and must load before plugins_loaded.
// Keep its global API intact; release builds bundle this library separately.
$swf_scheduler = SWF_DIR . 'libraries/action-scheduler/action-scheduler.php';
if ( ! is_readable( $swf_scheduler ) ) {
	$swf_scheduler = SWF_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}
require_once $swf_scheduler;
unset( $swf_scheduler );

require_once SWF_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( \SWForms\WordPress\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SWForms\WordPress\Lifecycle::class, 'deactivate' ) );

\SWForms\WordPress\Plugin::instance()->boot();
