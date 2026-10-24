<?php

declare(strict_types=1);

namespace SWForms\Core\Form;

use SWForms\Core\Logic\Evaluator;

/**
 * Structural validation of a form document (docs/schema.md).
 *
 * Errors are codes with parameters; the WordPress layer translates them.
 */
final class SchemaValidator
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
    public const MAX_COLUMNS = 6;
    public const MAX_DEPTH = 8;
    public const MAX_RULES = 25;

    /** @var list<array{path: string, code: string, params: array<string, string|int>}> */
    private array $errors = [];
    /** @var array<string, true> */
    private array $seenIds = [];

    public function __construct(private readonly TypeCatalog $types)
    {
    }

    /**
     * @param array<string, mixed> $document
     * @return list<array{path: string, code: string, params: array<string, string|int>}>
     */
    public function validate(array $document): array
    {
        $this->errors = [];
        $this->seenIds = [];

        $fields = $document['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields)) {
            $this->error('', 'invalid_document');
            return $this->errors;
        }
        $keys = [];
        $this->elements($fields, $keys, 1);
        if ($this->errors === []) {
            $this->logic(Tree::build($fields, $this->types));
        }
        return $this->errors;
    }

    /**
     * @param list<mixed>          $elements
     * @param array<string, true> $keys Keys used in the current data object.
     */
    private function elements(array $elements, array &$keys, int $depth): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                $this->error('', 'invalid_element');
                continue;
            }
            $id = $this->identify($element);
            if ($id === null) {
                continue;
            }
            if ($depth > self::MAX_DEPTH) {
                $this->error($id, 'too_deep', ['max' => self::MAX_DEPTH]);
                continue;
            }
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            if (!$this->types->has($type)) {
                $this->error($id, 'unknown_type', ['type' => $type]);
                continue;
            }
            if (isset($element['label']) && !is_string($element['label'])) {
                $this->error($id, 'invalid_label');
            }
            if ($this->types->isData($type)) {
                $key = $element['key'] ?? null;
                if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                    $this->error($id, 'invalid_key', ['key' => is_string($key) ? $key : '']);
                } elseif (isset($keys[$key])) {
                    $this->error($id, 'duplicate_key', ['key' => $key]);
                } else {
                    $keys[$key] = true;
                }
            }

            $container = $this->types->container($type);
            if ($container === TypeCatalog::CONTAINER_CHILDREN) {
                $children = $element['children'] ?? [];
                if (!is_array($children) || !array_is_list($children)) {
                    $this->error($id, 'invalid_children');
                    continue;
                }
                // A group is its own data object: keys only need to be unique inside it.
                $groupKeys = [];
                $this->elements($children, $groupKeys, $depth + 1);
            } elseif ($container === TypeCatalog::CONTAINER_COLUMNS) {
                $this->columns($id, $element['columns'] ?? null, $keys, $depth);
            }
        }
    }

    /**
     * @param array<string, true> $keys Columns are layout only: their fields share the surrounding data object.
     */
    private function columns(string $id, mixed $columns, array &$keys, int $depth): void
    {
        if (!is_array($columns) || !array_is_list($columns) || count($columns) < 1 || count($columns) > self::MAX_COLUMNS) {
            $this->error($id, 'invalid_columns', ['max' => self::MAX_COLUMNS]);
            return;
        }
        foreach ($columns as $column) {
            if (!is_array($column)) {
                $this->error($id, 'invalid_columns', ['max' => self::MAX_COLUMNS]);
                continue;
            }
            $columnId = $this->identify($column);
            if ($columnId === null) {
                continue;
            }
            $width = $column['width'] ?? null;
            if (!is_int($width) || $width < 1 || $width > 12) {
                $this->error($columnId, 'invalid_width');
            }
            $children = $column['children'] ?? [];
            if (!is_array($children) || !array_is_list($children)) {
                $this->error($columnId, 'invalid_children');
                continue;
            }
            $this->elements($children, $keys, $depth + 1);
        }
    }

    /**
     * @param array<mixed> $element
     */
    private function identify(array $element): ?string
    {
        $id = $element['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            $this->error('', 'invalid_id', ['id' => is_string($id) ? $id : '']);
            return null;
        }
        if (isset($this->seenIds[$id])) {
            $this->error($id, 'duplicate_id', ['id' => $id]);
            return null;
        }
        $this->seenIds[$id] = true;
        return $id;
    }

    private function logic(Tree $tree): void
    {
        /** @var array<string, list<string>> $dependencies */
        $dependencies = [];
        foreach ($tree->ids() as $id) {
            $dependencies[$id] = [];
            $parent = $tree->parentOf($id);
            if ($parent !== null) {
                $dependencies[$id][] = $parent;
            }
            $logic = $tree->node($id)['logic'] ?? null;
            if ($logic === null) {
                continue;
            }
            if (!is_array($logic)) {
                $this->error($id, 'invalid_logic');
                continue;
            }
            if (!in_array($logic['action'] ?? null, ['show', 'hide'], true) || !in_array($logic['match'] ?? null, ['all', 'any'], true)) {
                $this->error($id, 'invalid_logic');
            }
            $rules = $logic['rules'] ?? null;
            if (!is_array($rules) || !array_is_list($rules) || count($rules) > self::MAX_RULES) {
                $this->error($id, 'invalid_logic');
                continue;
            }
            foreach ($rules as $rule) {
                $field = $this->rule($tree, $id, $rule);
                if ($field !== null) {
                    $dependencies[$id][] = $field;
                }
            }
        }
        foreach ($this->cycles($dependencies) as $id) {
            $this->error($id, 'logic_cycle');
        }
    }

    /**
     * @return string|null The referenced field id when the rule is valid.
     */
    private function rule(Tree $tree, string $id, mixed $rule): ?string
    {
        if (!is_array($rule)) {
            $this->error($id, 'invalid_rule');
            return null;
        }
        $field = $rule['field'] ?? null;
        $operator = $rule['op'] ?? null;
        if (!is_string($operator) || !in_array($operator, Evaluator::OPERATORS, true)) {
            $this->error($id, 'invalid_operator', ['op' => is_string($operator) ? $operator : '']);
            return null;
        }
        if (isset($rule['value']) && !is_scalar($rule['value'])) {
            $this->error($id, 'invalid_rule');
            return null;
        }
        if (!is_string($field) || !$tree->isLeafData($field)) {
            $this->error($id, 'rule_unknown_field', ['field' => is_string($field) ? $field : '']);
            return null;
        }
        if ($field === $id) {
            $this->error($id, 'rule_self_reference');
            return null;
        }
        // A rule can see its own scope and enclosing scopes, never into a repeater row from outside.
        if (!$tree->scopeEncloses($tree->scopeOf($field), $tree->scopeOf($id))) {
            $this->error($id, 'rule_out_of_scope', ['field' => $field]);
            return null;
        }
        return $field;
    }

    /**
     * Nodes that are part of a dependency cycle (iterative DFS, colours: 1 = on stack, 2 = done).
     *
     * @param array<string, list<string>> $graph
     * @return list<string>
     */
    private function cycles(array $graph): array
    {
        $state = [];
        $inCycle = [];
        foreach (array_keys($graph) as $start) {
            if (isset($state[$start])) {
                continue;
            }
            $stack = [[$start, 0]];
            $path = [$start];
            $state[$start] = 1;
            while ($stack !== []) {
                $top = count($stack) - 1;
                [$node, $index] = $stack[$top];
                $edges = $graph[$node] ?? [];
                if ($index >= count($edges)) {
                    $state[$node] = 2;
                    array_pop($stack);
                    array_pop($path);
                    continue;
                }
                $stack[$top][1] = $index + 1;
                $next = $edges[$index];
                $nextState = $state[$next] ?? 0;
                if ($nextState === 1) {
                    $position = array_search($next, $path, true);
                    foreach (array_slice($path, $position === false ? 0 : $position) as $member) {
                        $inCycle[$member] = true;
                    }
                } elseif ($nextState === 0 && isset($graph[$next])) {
                    $state[$next] = 1;
                    $stack[] = [$next, 0];
                    $path[] = $next;
                }
            }
        }
        return array_keys($inCycle);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function error(string $path, string $code, array $params = []): void
    {
        $this->errors[] = ['path' => $path, 'code' => $code, 'params' => $params];
    }
}
