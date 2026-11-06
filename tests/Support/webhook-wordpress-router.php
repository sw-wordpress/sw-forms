<?php

/** Loopback REST test router; the destination exception requires a per-run secret header. */

declare(strict_types=1);

$key = getenv('SWF_TEST_HTTP_KEY');
if (!$key || !hash_equals($key, (string) ($_SERVER['HTTP_X_SWF_TEST_KEY'] ?? ''))) {
    http_response_code(403);
    exit;
}
define('REST_REQUEST', true);
define('WP_USE_THEMES', false);
require getenv('WP_ROOT') . '/wp-load.php';
$url = (string) getenv('SWF_TEST_WEBHOOK_URL');
add_filter('swf_webhook_allow_private_url', static fn (bool $allowed, string $candidate): bool => $candidate === $url || $allowed, 10, 2);
add_filter('pre_wp_mail', '__return_true');
add_filter('action_scheduler_allow_async_request_runner', '__return_false');
$path = explode('/wp-json/', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 2)[1] ?? '';
rest_get_server()->serve_request('/' . $path);
