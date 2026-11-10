<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress PHPUnit conventions.

/** Isolated updater tests: this core installation is never the development WordPress. */

$tests_dir = __DIR__ . '/vendor/wp-phpunit/wp-phpunit';
putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
require_once $tests_dir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', static function (): void {
    define('SWF_FILE', dirname(__DIR__, 2) . '/sw-forms.php');
    define('SWF_DIR', dirname(__DIR__, 2) . '/');
    define('SWF_URL', 'http://example.org/wp-content/plugins/sw-forms/');
    preg_match("/define\( 'SWF_VERSION', '([^']+)'/", file_get_contents(SWF_FILE), $matches);
    define('SWF_VERSION', $matches[1]);
    require SWF_DIR . 'includes/update/class-sw-forms-update-provider.php';
});
if (getenv('WP_MULTISITE') === '1') {
    define('WP_TESTS_MULTISITE', true);
}
require $tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/tests/SWF_Update_TestCase.php';
require __DIR__ . '/tests/SWF_Verified_Update_Exit.php';
