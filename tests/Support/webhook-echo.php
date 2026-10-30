<?php

/** Loopback echo receiver used only by the real HTTP webhook integration test. */

declare(strict_types=1);

$body = file_get_contents('php://input');
$key = (string) getenv('SWF_WEBHOOK_TEST_SECRET');
$signature = (string) ($_SERVER['HTTP_X_SWFORMS_SIGNATURE'] ?? '');
$valid = hash_equals('sha256=' . hash_hmac('sha256', $body, $key), $signature);
http_response_code($valid ? 200 : 401);
header('Content-Type: application/json');
echo json_encode(['validSignature' => $valid, 'method' => $_SERVER['REQUEST_METHOD'], 'payload' => json_decode($body, true), 'body' => $body]);
