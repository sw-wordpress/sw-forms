<?php

declare(strict_types=1);

namespace SWForms\Core\Webhooks;

use SWForms\Core\Form\Tree;

final class Payload
{
    public function __construct(private readonly Tree $tree)
    {
    }

    /**
     * Map immutable ids to flat receiver keys. A repeater is mapped as a whole list.
     * Reads the final public data so hidden fields remain absent and data filters apply.
     *
     * @param array<string, mixed> $data
     * @param list<array{field: string, target: string}> $mapping
     * @return array<string, mixed>
     */
    public function map(array $data, array $mapping): array
    {
        if ($mapping === []) {
            return $data;
        }
        $out = [];
        foreach ($mapping as $item) {
            $id = $item['field'];
            if (!$this->tree->isData($id) || $this->tree->scopeOf($id) !== null) {
                continue;
            }
            $path = [];
            $current = $id;
            while ($current !== null) {
                if ($this->tree->isData($current)) {
                    $key = $this->tree->node($current)['key'] ?? $current;
                    array_unshift($path, is_string($key) ? $key : $current);
                }
                $current = $this->tree->parentOf($current);
            }
            $value = $data;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    continue 2;
                }
                $value = $value[$key];
            }
            $out[$item['target']] = $value;
        }
        return $out;
    }

    /**
     * The event id is supplied once by the caller and retained for every retry.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function envelope(string $eventId, int $formId, string $title, int $version, int $entryId, string $date, array $data): array
    {
        return [
            'event' => 'entry.created',
            'eventId' => $eventId,
            'form' => ['id' => $formId, 'title' => $title, 'version' => $version],
            'entry' => ['id' => $entryId, 'date' => $date],
            'data' => (object) $data,
        ];
    }
}
