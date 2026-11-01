<?php

declare(strict_types=1);

namespace SWForms\Core\Form;

/**
 * Presentation counters for a form document: data fields, steps and conditional rules.
 * Used by the template catalog; counts never change the document itself.
 */
final class DocumentStats
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $document
     * @param TypeCatalog|null $types defaults to the built-in catalog
     * @return array{fields: int, steps: int, conditions: int, conditionalRequired: int}
     */
    public static function measure(array $document, ?TypeCatalog $types = null): array
    {
        $catalog = $types ?? StaticTypeCatalog::builtIn();
        $fields = 0;
        $conditions = 0;
        $conditionalRequired = 0;
        $breaks = 0;

        $walk = static function (array $elements) use (&$walk, $catalog, &$fields, &$conditions, &$conditionalRequired, &$breaks): void {
            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $type = is_string($element['type'] ?? null) ? $element['type'] : '';
                if ($type === 'page-break') {
                    ++$breaks;
                }
                if ($catalog->isData($type)) {
                    ++$fields;
                }
                $logic = $element['logic'] ?? null;
                if (is_array($logic) && is_array($logic['rules'] ?? null)) {
                    ++$conditions;
                    if (($logic['action'] ?? '') === 'require') {
                        ++$conditionalRequired;
                    }
                }
                if (is_array($element['children'] ?? null)) {
                    $walk($element['children']);
                }
                if (is_array($element['columns'] ?? null)) {
                    foreach ($element['columns'] as $column) {
                        if (is_array($column) && is_array($column['children'] ?? null)) {
                            $walk($column['children']);
                        }
                    }
                }
            }
        };

        $walk(is_array($document['fields'] ?? null) ? $document['fields'] : []);

        $steps = ($document['settings']['steps']['enabled'] ?? null) === false ? 1 : $breaks + 1;

        return [
            'fields' => $fields,
            'steps' => $steps,
            'conditions' => $conditions,
            'conditionalRequired' => $conditionalRequired,
        ];
    }
}
