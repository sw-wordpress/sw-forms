<?php

/** Two-process submission fixture. Only used against disposable WordPress by PHPUnit. */

declare(strict_types=1);

require getenv('WP_ROOT') . '/wp-load.php';
$input = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$index = $argv[2];
if (($input['mode'] ?? '') === 'hold') {
    $lock = \SWForms\WordPress\Submission\SubmissionLock::acquire($input['form']);
    if ($lock === null) {
        throw new RuntimeException('Could not acquire fixture lock.');
    }
    file_put_contents($input['directory'] . '/ready-' . $index, '1');
    // Process exit intentionally releases the database connection without release().
    exit;
}
add_filter('pre_wp_mail', '__return_true');
add_action('swf_after_actions', static function () use ($input): void {
    file_put_contents($input['directory'] . '/actions', "action\n", FILE_APPEND | LOCK_EX);
});
add_filter('swf_validation_errors', static function (array $errors): array {
    usleep(200000); // Keep the read-to-record interval open long enough to expose an unprotected race.
    return $errors;
});
file_put_contents($input['directory'] . '/ready-' . $index, '1');
$deadline = microtime(true) + 10;
while (!is_file($input['directory'] . '/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Submission barrier timed out.');
    }
    usleep(1000);
}
$result = \SWForms\WordPress\Submission\SubmissionService::create_default()->submit(
    $input['form'],
    ['name' => 'Worker ' . $index],
    ['token' => $input['tokens'][(int) $index], 'ip' => '192.0.2.16']
);
echo json_encode($result->to_array(), JSON_THROW_ON_ERROR);
