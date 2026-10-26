<?php
/**
 * Uninstall handler. Data is only removed when "delete data on uninstall" is enabled.
 *
 * @package SWForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Release ZIPs ship the Mozart loader only, source checkouts Composer's.
$swf_loader = require is_readable( __DIR__ . '/vendor-prefixed/autoload.php' ) ? __DIR__ . '/vendor-prefixed/autoload.php' : __DIR__ . '/vendor/autoload.php';
$swf_loader->addPsr4( 'SWForms\\', __DIR__ . '/src/' );

\SWForms\WordPress\Lifecycle::uninstall();
