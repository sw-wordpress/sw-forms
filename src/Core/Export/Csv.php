<?php

declare(strict_types=1);

namespace SWForms\Core\Export;

final class Csv
{
    /** @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function flatten(array $data, string $prefix = 'data.'): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value) && !array_is_list($value)) {
                $out += self::flatten($value, $path . '.');
            } else {
                $out[$path] = is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : (is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : ''));
            }
        }
        return $out;
    }

    public static function safeCell(string $value): string
    {
        return preg_match('/^(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $value) === 1 ? "'" . $value : $value;
    }

    /** @param resource $stream
     * @param list<string> $values
     */
    public static function row($stream, array $values): void
    {
        if (fputcsv($stream, array_map(self::safeCell(...), $values), ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('CSV output failed.');
        }
    }
}
