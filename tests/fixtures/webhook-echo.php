<?php

/**
 * Webhook receiver for tests (`php -S 127.0.0.1:<port> webhook-echo.php`): appends every request as
 * a JSON line to the file in `SWF_ECHO_LOG` and answers with `?status=<code>` (default 200).
 * 3xx answers redirect to example.org.
 */

declare(strict_types=1);

$log = (string) getenv('SWF_ECHO_LOG');
$status = (int) ($_GET['status'] ?? 200);
if ($log !== '') {
    $record = [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
        'body' => (string) file_get_contents('php://input'),
    ];
    file_put_contents($log, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
}
if ($status >= 300 && $status < 400) {
    header('Location: https://example.org/elsewhere');
}
http_response_code($status > 0 ? $status : 200);
header('Content-Type: application/json');
echo json_encode(['received' => $status < 300]);
