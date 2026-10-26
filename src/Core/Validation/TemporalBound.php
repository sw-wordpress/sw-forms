<?php

declare(strict_types=1);

namespace SWForms\Core\Validation;

use DateTimeImmutable;

/** Resolves a deliberately small relative-date grammar against an explicit clock. */
final class TemporalBound
{
    public static function resolve(string $bound, string $type, DateTimeImmutable $now): string
    {
        if (!in_array($type, ['date', 'datetime-local'], true)) {
            return $bound;
        }
        if (preg_match('/^(today|now)(?:\s*([+-])\s*(\d{1,4})\s*(days?|weeks?|months?|years?|hours?|minutes?))?$/i', trim($bound), $parts) !== 1) {
            return $bound;
        }
        $date = strtolower($parts[1]) === 'today' ? $now->setTime(0, 0) : $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        if (isset($parts[2], $parts[3], $parts[4])) {
            $shifted = $date->modify($parts[2] . $parts[3] . ' ' . strtolower($parts[4]));
            if ($shifted === false) {
                return $bound;
            }
            $date = $shifted;
        }
        return $date->format($type === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i');
    }
}
