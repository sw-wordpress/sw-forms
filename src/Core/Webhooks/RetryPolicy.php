<?php

declare(strict_types=1);

namespace SWForms\Core\Webhooks;

final class RetryPolicy
{
    public const DELAYS = [60, 300, 1800, 7200, 43200];
    public const MAX_ATTEMPTS = 6;

    /** Failure after attempt 1 schedules retry 1, after attempt 6 is terminal. */
    public static function delay(int $attempt): ?int
    {
        return self::DELAYS[$attempt - 1] ?? null;
    }
}
