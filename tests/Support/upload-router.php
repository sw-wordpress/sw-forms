<?php

/** Loopback-only HTTP fixture. Fault injection is protected by a per-run random key. */

declare(strict_types=1);

$key = getenv('SWF_TEST_HTTP_KEY');
if (!$key || !hash_equals($key, (string) ($_SERVER['HTTP_X_SWF_TEST_KEY'] ?? ''))) {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../coverage/bootstrap.php';
require getenv('WP_ROOT') . '/wp-load.php';
// Match the PHPUnit fixture locale without changing the site's language setting.
switch_to_locale('en_US');
$uploadRoot = getenv('SWF_TEST_UPLOAD_ROOT');
add_filter('upload_dir', static function (array $directory) use ($uploadRoot): array {
    $directory['basedir'] = $uploadRoot;
    $directory['baseurl'] = 'http://localhost/test-uploads';
    $directory['path'] = $uploadRoot;
    $directory['url'] = $directory['baseurl'];
    $directory['subdir'] = '';
    return $directory;
});
$fault = $_SERVER['HTTP_X_SWF_TEST_FAULT'] ?? '';
if ($fault === 'second-upload') {
    $moves = 0;
    add_filter('wp_handle_upload_prefilter', static function (array $file) use (&$moves): array {
        if (++$moves === 2) {
            $file['error'] = 'Injected failure on second upload.';
        }
        return $file;
    });
} elseif ($fault === 'missing-file') {
    add_filter('pre_move_uploaded_file', '__return_false');
} elseif ($fault === 'missing-url') {
    add_filter('wp_handle_upload', static function (array $result): array {
        unset($result['url']);
        return $result;
    });
} elseif ($fault === 'entry-insert') {
    global $wpdb;
    $wpdb->suppress_errors(true);
    add_filter('query', static function (string $sql) use ($wpdb): string {
        return str_starts_with($sql, 'INSERT INTO `' . $wpdb->prefix . 'swf_entries`') ? 'SWF_INTENTIONAL_INVALID_SQL' : $sql;
    });
} elseif ($fault === 'data-filter') {
    add_filter('swf_submission_data', static function (): never {
        throw new RuntimeException('Injected persistence failure.');
    });
} elseif ($fault === 'directory') {
    add_filter('upload_dir', static function (array $directory): array {
        $directory['error'] = 'Injected upload directory failure.';
        return $directory;
    }, 100);
}
require getenv('WP_ROOT') . '/index.php';
