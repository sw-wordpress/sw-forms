<?php
/**
 * Uninstall handler. Data is only removed when "delete data on uninstall" is enabled.
 *
 * @package SWForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$swf_uninstall_autoload = __DIR__ . '/vendor-prefixed/autoload.php';
if ( ! is_readable( $swf_uninstall_autoload ) ) {
	$swf_uninstall_autoload = __DIR__ . '/vendor/autoload.php';
}
$swf_uninstall_loader = require $swf_uninstall_autoload;
$swf_uninstall_loader->addPsr4( 'SWForms\\', __DIR__ . '/src/' );
unset( $swf_uninstall_autoload, $swf_uninstall_loader );

\SWForms\WordPress\Lifecycle::uninstall();
