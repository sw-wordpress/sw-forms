<?php

/** This suite deliberately requires a real, disposable WordPress database. */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$wordpress = getenv('WP_ROOT');
if (!$wordpress || !is_file($wordpress . '/wp-load.php')) {
    throw new RuntimeException('Set WP_ROOT to an installed WordPress with SW Forms activated.');
}
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once $wordpress . '/wp-load.php';
if (!function_exists('swf_submit')) {
    throw new RuntimeException('Activate SW Forms in the test WordPress first.');
}
