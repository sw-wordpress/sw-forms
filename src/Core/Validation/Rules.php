<?php

declare(strict_types=1);

namespace SWForms\Core\Validation;

use SWForms\Core\Logic\Evaluator;

/**
 * Pure value checks used by the field types. Every method answers "is the value acceptable".
 */
final class Rules
{
    public static function email(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function url(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }

    public static function tel(string $value): bool
    {
        return preg_match('/^[0-9+()\/\s.-]{3,40}$/', $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    /** ISO date (the format of `<input type="date">`). */
    public static function date(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** HTML local time, with optional seconds. */
    public static function time(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) === 1;
    }

    /** Local date and time, deliberately without timezone conversion. */
    public static function dateTime(string $value): bool
    {
        $parts = explode('T', $value);
        return count($parts) === 2 && self::date($parts[0]) && self::time($parts[1]);
    }

    /** Floating point tolerant step check using the configured minimum as the base. */
    public static function step(float $value, float $step, float $base = 0.0): bool
    {
        if (!is_finite($value) || !is_finite($step) || !is_finite($base) || $step <= 0) {
            return false;
        }
        $steps = ($value - $base) / $step;
        return is_finite($steps) && abs($steps - round($steps)) <= 1e-7;
    }

    /** Explicit configurable requirements, rather than an estimated entropy score. */
    public static function password(string $value, int $minLength, bool $mixed): bool
    {
        if (self::length($value) < $minLength) {
            return false;
        }
        return !$mixed || (preg_match('/\p{Lu}/u', $value) === 1
            && preg_match('/\p{Ll}/u', $value) === 1
            && preg_match('/\p{N}/u', $value) === 1
            && preg_match('/[^\p{L}\p{N}\s]/u', $value) === 1);
    }

    public static function number(string $value): ?float
    {
        return Evaluator::number($value);
    }

    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * Whole-value match against an admin defined pattern (HTML `pattern` semantics).
     *
     * @return bool|null Null when the pattern itself is invalid (treated as "no constraint" by callers).
     */
    public static function pattern(string $pattern, string $value): ?bool
    {
        if ($pattern === '') {
            return true;
        }
        $regex = '~^(?:' . str_replace('~', '\~', $pattern) . ')$~u';
        // The pattern is admin input: a compile error must not surface as a PHP warning on a public request.
        set_error_handler(static fn (): bool => true);
        try {
            $result = preg_match($regex, $value);
        } finally {
            restore_error_handler();
        }
        return $result === false ? null : $result === 1;
    }

    /**
     * @param list<string> $allowed
     */
    public static function option(string $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }
}
