<?php

/** Exercise the real uninstall entry point without WordPress or data-deletion opt-in. */

declare(strict_types=1);

define('WP_UNINSTALL_PLUGIN', 'sw-forms/sw-forms.php');

function wp_clear_scheduled_hook(string $hook): int
{
    $GLOBALS['swf_uninstall_cleared'][] = $hook;
    return 0;
}

function get_option(string $name, mixed $default = false): mixed
{
    return $default;
}

function sanitize_email(string $email): string
{
    return $email;
}

function is_email(string $email): string|false
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

$swf_uninstall_test_root = $argv[1] ?? dirname(__DIR__);
require $swf_uninstall_test_root . '/uninstall.php';
if ($GLOBALS['swf_uninstall_cleared'] !== ['swf_daily_maintenance', 'swf_recover_webhooks']) {
    throw new RuntimeException('Uninstall failed to stop plugin maintenance.');
}
echo 'PASS: Uninstall loads the production autoloader, clears maintenance and preserves data without opt-in.' . PHP_EOL;
