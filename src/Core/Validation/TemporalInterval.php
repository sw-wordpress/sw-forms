<?php

declare(strict_types=1);

namespace SWForms\Core\Validation;

/** Ordered pairs stay list values for the existing shared conditional-logic contract. */
final class TemporalInterval
{
    /** @return list<string> Empty, or [from, to]. Malformed input stays incomplete and fails validation. */
    public static function parse(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw)) {
            return ['', '!'];
        }
        $parts = [$raw['from'] ?? $raw[0] ?? '', $raw['to'] ?? $raw[1] ?? ''];
        foreach ($parts as &$part) {
            if (!is_string($part)) {
                return ['', '!'];
            }
            $part = mb_substr(trim($part), 0, 200, 'UTF-8');
        }
        unset($part);
        return $parts === ['', ''] ? [] : $parts;
    }

    /** @param list<string> $value */
    public static function problem(array $value, string $type, string $min = '', string $max = '', bool $allowEqual = true, ?int $step = null): ?string
    {
        if (count($value) !== 2 || $value[0] === '' || $value[1] === '') {
            return 'required';
        }
        foreach ($value as $part) {
            if (!self::valid($part, $type)) {
                return $type;
            }
            if (self::valid($min, $type) && self::key($part, $type) < self::key($min, $type)) {
                return 'min';
            }
            if (self::valid($max, $type) && self::key($part, $type) > self::key($max, $type)) {
                return 'max';
            }
            if ($type === 'time' && $step !== null && $step > 0) {
                $base = self::valid($min, $type) ? self::seconds($min) : 0;
                if (!Rules::step(self::seconds($part), $step, $base)) {
                    return 'step';
                }
            }
        }
        $comparison = strcmp(self::key($value[0], $type), self::key($value[1], $type));
        return $comparison > 0 || (!$allowEqual && $comparison === 0) ? 'interval_order' : null;
    }

    public static function valid(string $value, string $type): bool
    {
        return $type === 'date' ? Rules::date($value) : Rules::time($value);
    }

    private static function key(string $value, string $type): string
    {
        return $type === 'time' && strlen($value) === 5 ? $value . ':00' : $value;
    }

    private static function seconds(string $value): int
    {
        $parts = explode(':', $value);
        return (int) $parts[0] * 3600 + (int) ($parts[1] ?? 0) * 60 + (int) ($parts[2] ?? 0);
    }
}
