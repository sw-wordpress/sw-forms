<?php

declare(strict_types=1);

namespace SWForms\Core\Webhooks;

final class Signature
{
    /** Sign the exact HTTP body bytes, including form encoding when applicable. */
    public static function sign(string $body, string $secret): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('A webhook signing secret is required.');
        }
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }
}
