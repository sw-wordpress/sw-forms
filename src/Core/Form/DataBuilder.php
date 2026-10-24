<?php

declare(strict_types=1);

namespace SWForms\Core\Form;

use SWForms\Core\Logic\Visibility;

/**
 * Turns clean values (keyed by element id) into the public data shape (keyed by element key).
 *
 * Shape (docs/schema.md): columns are transparent, a group is an object, a repeatable group a
 * list of objects, hidden elements are omitted.
 */
final class DataBuilder
{
    /** @var (callable(string, array<string, mixed>, mixed): string)|null */
    private $format = null;

    public function __construct(private readonly Tree $tree)
    {
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function build(array $values, Visibility $visibility): array
    {
        return $this->object($this->tree->rootIds(), $values, $visibility, '');
    }

    /**
     * @param list<string>         $ids
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function object(array $ids, array $values, Visibility $visibility, string $prefix): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (!$visibility->isVisible($id, $prefix)) {
                continue;
            }
            if (!$this->tree->isData($id)) {
                $out += $this->object($this->tree->childIds($id), $values, $visibility, $prefix);
                continue;
            }
            $key = $this->key($id);
            if ($this->tree->isRepeater($id)) {
                $rows = [];
                foreach ($this->rows($values[$id] ?? null) as $index => $row) {
                    $rows[] = $this->object($this->tree->childIds($id), $row, $visibility, $prefix . $id . '[' . $index . '].');
                }
                $out[$key] = $rows;
            } elseif ($this->tree->isLeafData($id)) {
                $out[$key] = $values[$id] ?? '';
            } else {
                $out[$key] = $this->object($this->tree->childIds($id), $values, $visibility, $prefix);
            }
        }
        return $out;
    }

    /**
     * Visible leaf values as rows for the search index (one row per field and repeater row).
     *
     * @param array<string, mixed>                                      $values
     * @param (callable(string, array<string, mixed>, mixed): string)|null $format Display text of a value
     *        (receives element id, element and value); defaults to {@see DataBuilder::stringify()}.
     * @return list<array{field: string, row: string, value: string}>
     */
    public function flatten(array $values, Visibility $visibility, ?callable $format = null): array
    {
        $out = [];
        $this->format = $format;
        $this->flattenInto($this->tree->rootIds(), $values, $visibility, '', $out);
        $this->format = null;
        return $out;
    }

    /**
     * @param list<string>                                             $ids
     * @param array<string, mixed>                                     $values
     * @param list<array{field: string, row: string, value: string}> $out
     */
    private function flattenInto(array $ids, array $values, Visibility $visibility, string $prefix, array &$out): void
    {
        foreach ($ids as $id) {
            if (!$visibility->isVisible($id, $prefix)) {
                continue;
            }
            if ($this->tree->isRepeater($id)) {
                foreach ($this->rows($values[$id] ?? null) as $index => $row) {
                    $this->flattenInto($this->tree->childIds($id), $row, $visibility, $prefix . $id . '[' . $index . '].', $out);
                }
                continue;
            }
            if ($this->tree->isLeafData($id)) {
                $value = $values[$id] ?? '';
                $text = $this->format !== null ? ($this->format)($id, $this->tree->node($id), $value) : self::stringify($value);
                $out[] = ['field' => $id, 'row' => $prefix, 'value' => $text];
                continue;
            }
            $this->flattenInto($this->tree->childIds($id), $values, $visibility, $prefix, $out);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? array_values($rows) : [] as $row) {
            $out[] = is_array($row) ? $row : [];
        }
        return $out;
    }

    private function key(string $id): string
    {
        $key = $this->tree->node($id)['key'] ?? $id;
        return is_string($key) && $key !== '' ? $key : $id;
    }

    public static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
