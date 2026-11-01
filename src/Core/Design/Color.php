<?php

declare(strict_types=1);

namespace SWForms\Core\Design;

/**
 * Hex colour parsing and WCAG 2.x contrast helpers for user defined design tokens.
 *
 * @since 0.6.0
 */
final class Color
{
    public const DARK_TEXT = '#111827';
    public const LIGHT_TEXT = '#ffffff';

    /** `#abc` or `#aabbcc` (any case) as lowercase `#aabbcc`, otherwise null. */
    public static function hex(mixed $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $match)) {
            return null;
        }
        $digits = strtolower($match[1]);
        if (3 === strlen($digits)) {
            $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
        }
        return '#' . $digits;
    }

    /** Relative luminance (WCAG 2.2, 1.4.3) of a normalised `#aabbcc` colour. */
    public static function luminance(string $hex): float
    {
        $channels = array_map(
            static function (string $pair): float {
                $value = hexdec($pair) / 255;
                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1), 2)
        );
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrast(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** Text colour (white or near black) with the higher contrast on the given background. */
    public static function readableOn(string $background): string
    {
        return self::contrast($background, self::LIGHT_TEXT) >= self::contrast($background, self::DARK_TEXT)
            ? self::LIGHT_TEXT
            : self::DARK_TEXT;
    }
}
