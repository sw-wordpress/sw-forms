<?php

declare(strict_types=1);

namespace SWForms\Core\Template;

/**
 * Merge tag syntax: `{name}` or `{name:argument}`, e.g. `{field:email}`, `{entry:id}`, `{all_fields}`.
 * Unknown tags (resolver returns null) are left untouched.
 */
final class MergeTags
{
    public const PATTERN = '/\{([a-z][a-z0-9_]*)(?::([^{}\r\n]{1,200}))?\}/';

    /**
     * @param callable(string, string): ?string $resolver Receives tag name and argument.
     */
    public static function replace(string $template, callable $resolver): string
    {
        $result = preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($resolver): string {
                $value = $resolver($match[1], $match[2] ?? '');
                return $value ?? $match[0];
            },
            $template
        );
        return $result ?? $template;
    }

    /**
     * @return list<array{0: string, 1: string}> Tag names with arguments in order of appearance.
     */
    public static function tags(string $template): array
    {
        if (preg_match_all(self::PATTERN, $template, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        $tags = [];
        foreach ($matches as $match) {
            $tags[] = [$match[1], $match[2] ?? ''];
        }
        return $tags;
    }
}
