<?php

/**
 * Smoke-test a source checkout or staged release without a WordPress database.
 * Usage: php tests/bootstrap.php [plugin-directory]
 *
 * @package SWForms
 */

declare(strict_types=1);

define('ABSPATH', '/test/wordpress/');

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/sw-forms/';
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['test_actions'][ $hook ][] = $callback;
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['test_filters'][ $hook ][] = $callback;
}

function add_shortcode(string $tag, mixed $callback): void
{
    $GLOBALS['test_shortcodes'][ $tag ] = $callback;
}

function register_activation_hook(string $file, mixed $callback): void
{
    $GLOBALS['test_activation'] = $callback;
}

function register_deactivation_hook(string $file, mixed $callback): void
{
    $GLOBALS['test_deactivation'] = $callback;
}

function is_admin(): bool
{
    return true;
}

$swf_test_root = $argv[1] ?? dirname(__DIR__);
require $swf_test_root . '/sw-forms.php';

foreach (array( 'test_activation', 'test_deactivation' ) as $swf_test_callback) {
    if (! is_callable($GLOBALS[ $swf_test_callback ] ?? null)) {
        throw new RuntimeException('Lifecycle callback could not be loaded: ' . $swf_test_callback);
    }
}

if (! class_exists(\SWForms\Core\Logic\Evaluator::class)) {
    throw new RuntimeException('Core classes could not be loaded.');
}

if (! is_callable($GLOBALS['test_shortcodes']['sw_form'] ?? null)) {
    throw new RuntimeException('Form shortcode was not registered.');
}

foreach (array( 'admin_menu', 'rest_api_init', 'wp_enqueue_scripts', 'template_redirect' ) as $swf_test_hook) {
    if (! isset($GLOBALS['test_actions'][ $swf_test_hook ])) {
        throw new RuntimeException('Integration was not registered: ' . $swf_test_hook);
    }
}

foreach (array( 'swf_render_form', 'swf_get_form', 'swf_submit' ) as $swf_test_function) {
    if (! function_exists($swf_test_function)) {
        throw new RuntimeException('Public API was not loaded: ' . $swf_test_function);
    }
}

if (isset($GLOBALS['test_actions']['admin_notices'])) {
    throw new RuntimeException('Plugin reported a bootstrap error.');
}

echo 'PASS: Plugin bootstrap, lifecycle callbacks, core classes, shortcode, integrations and public API.' . PHP_EOL;
