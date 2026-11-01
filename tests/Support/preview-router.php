<?php

/**
 * Loopback-only fixture that renders the starter template preview as a logged-in form manager.
 * The per-run key protects the fixture; the nonce is created in-request for that user.
 */

declare(strict_types=1);

$key = getenv('SWF_TEST_HTTP_KEY');
if (! $key || ! hash_equals($key, (string) ( $_SERVER['HTTP_X_SWF_TEST_KEY'] ?? '' ))) {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../coverage/bootstrap.php';
require getenv('WP_ROOT') . '/wp-load.php';
// Match the PHPUnit fixture locale without changing the site's language setting.
switch_to_locale('en_US');

$admins = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    )
);
wp_set_current_user((int) ( $admins[0] ?? 0 ));

if (isset($_GET['swf_template_preview'])) {
    $_GET['_wpnonce'] = wp_create_nonce(\SWForms\WordPress\Frontend\TemplatePreview::NONCE_ACTION);
}

require getenv('WP_ROOT') . '/index.php';
