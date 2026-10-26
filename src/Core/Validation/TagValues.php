<?php

declare(strict_types=1);

namespace SWForms\Core\Validation;

/** Bounded plain-text tag parsing. Values are escaped by their output context. */
final class TagValues
{
    /** @return list<string> */
    public static function parse(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            if (!is_string($value)) {
                continue;
            }
            foreach (preg_split('/[,\r\n]+/', $value) ?: [] as $tag) {
                $tag = mb_substr(trim($tag), 0, 201, 'UTF-8');
                if ($tag !== '' && !in_array($tag, $out, true)) {
                    $out[] = $tag;
                    // Keep one over the configurable maximum so validation rejects excess input.
                    if (count($out) === 101) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }
}
