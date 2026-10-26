<?php

declare(strict_types=1);

namespace SWForms\Core\Webhook;

use SWForms\Core\Form\Tree;

/**
 * Field mapping of a webhook: picks fields from the submission data and renames them for the
 * receiver. Target names may contain dots for nesting (`contact.email` → `{"contact":{"email":…}}`,
 * form encoded `contact[email]=…`).
 *
 * Mappable are data elements outside repeaters (fields, groups and whole repeaters), referenced
 * by their immutable id so that renaming a key does not break a mapping.
 */
final class PayloadMapper
{
    public const NAME_PATTERN = '/^[A-Za-z0-9_\-:]{1,100}(\.[A-Za-z0-9_\-:]{1,100}){0,9}$/';

    public function __construct(private readonly Tree $tree)
    {
    }

    public function isMappable(string $id): bool
    {
        return $this->tree->isData($id) && $this->tree->scopeOf($id) === null;
    }

    public static function validName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Data keys from the root to the element, e.g. `['address', 'city']` for a field in a group.
     *
     * @return list<string>
     */
    public function keyPath(string $id): array
    {
        $path = [];
        $current = $id;
        while ($current !== null) {
            if ($this->tree->isData($current)) {
                $key = $this->tree->node($current)['key'] ?? '';
                array_unshift($path, is_string($key) ? $key : '');
            }
            $current = $this->tree->parentOf($current);
        }
        return $path;
    }

    /**
     * @param array<string, mixed>                       $data    Submission data keyed by field key.
     * @param list<array{field: string, name: string}>   $mapping
     * @return array<string, mixed>
     */
    public function apply(array $data, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $row) {
            if (!$this->isMappable($row['field']) || !self::validName($row['name'])) {
                continue;
            }
            $value = $data;
            foreach ($this->keyPath($row['field']) as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    // Hidden by conditional logic: like in the data shape, the field is omitted.
                    continue 2;
                }
                $value = $value[$key];
            }
            self::set($out, explode('.', $row['name']), $value);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $target
     * @param list<string>         $path
     */
    private static function set(array &$target, array $path, mixed $value): void
    {
        $key = array_shift($path);
        if ($key === null) {
            return;
        }
        if ($path === []) {
            $target[$key] = $value;
            return;
        }
        if (!isset($target[$key]) || !is_array($target[$key])) {
            $target[$key] = [];
        }
        self::set($target[$key], $path, $value);
    }
}
