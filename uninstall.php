<?php
/**
 * Uninstall handler. Data is only removed when "delete data on uninstall" is enabled.
 *
 * @package SWForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

\SWForms\WordPress\Lifecycle::uninstall();
