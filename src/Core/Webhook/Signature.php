<?php

declare(strict_types=1);

namespace SWForms\Core\Webhook;

/**
 * Webhook signature header `X-SWForms-Signature: t=<unix time>,v1=<hex hmac>`.
 *
 * The HMAC-SHA256 covers `<t>.<raw body>`, so receivers can reject replays of old requests by
 * checking the timestamp. Several `v1` values may be present (secret rotation on the receiver).
 */
final class Signature
{
    public const HEADER = 'X-SWForms-Signature';
    public const DEFAULT_TOLERANCE = 300;

    public static function header(string $secret, string $body, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . self::hash($secret, $body, $timestamp);
    }

    public static function hash(string $secret, string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Reference implementation for receivers (also used by the tests).
     */
    public static function verify(string $header, string $secret, string $body, int $now, int $tolerance = self::DEFAULT_TOLERANCE): bool
    {
        $timestamp = null;
        $hashes = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $hashes[] = $value;
            }
        }
        if ($timestamp === null || abs($now - $timestamp) > $tolerance) {
            return false;
        }
        $expected = self::hash($secret, $body, $timestamp);
        foreach ($hashes as $hash) {
            if (hash_equals($expected, $hash)) {
                return true;
            }
        }
        return false;
    }
}
