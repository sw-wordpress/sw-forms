<?php

declare(strict_types=1);

namespace SWForms\Core\Webhook;

/**
 * When a failed delivery is retried: after a network error, 408, 425, 429 or a 5xx response,
 * with growing delays. Other responses (e.g. 400, 401, 404) are configuration problems that a
 * retry would not fix, so they fail immediately.
 */
final class RetryPolicy
{
    /** Seconds before retry 1..n: 1 min, 5 min, 30 min, 2 h, 12 h. */
    public const DEFAULT_DELAYS = [60, 300, 1800, 7200, 43200];

    /** @var list<int> */
    private readonly array $delays;

    /**
     * @param list<int> $delays Seconds before each retry; an empty list disables retries.
     */
    public function __construct(array $delays = self::DEFAULT_DELAYS)
    {
        $clean = [];
        foreach ($delays as $delay) {
            $clean[] = max(1, (int) $delay);
        }
        $this->delays = array_slice($clean, 0, 20);
    }

    public function maxAttempts(): int
    {
        return count($this->delays) + 1;
    }

    /**
     * @param int      $attempt Number of the attempt that just failed (1 = first delivery).
     * @param int|null $status  HTTP status, null on a network error / timeout.
     * @return int|null Seconds until the next attempt, null if the delivery failed for good.
     */
    public function nextDelay(int $attempt, ?int $status): ?int
    {
        if (!self::isRetryable($status) || $attempt < 1) {
            return null;
        }
        return $this->delays[$attempt - 1] ?? null;
    }

    public static function isSuccess(?int $status): bool
    {
        return $status !== null && $status >= 200 && $status < 300;
    }

    public static function isRetryable(?int $status): bool
    {
        return $status === null || in_array($status, [408, 425, 429], true) || $status >= 500;
    }
}
