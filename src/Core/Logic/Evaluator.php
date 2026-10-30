<?php

declare(strict_types=1);

namespace SWForms\Core\Logic;

use SWForms\Core\Form\Tree;

/**
 * Evaluates conditional visibility for every node of a form.
 *
 * Contract shared with the TypeScript twin in apps/admin/shared/logic.ts; both are tested
 * against tests/fixtures/logic/*.json.
 *
 * - Values are keyed by element id. A repeatable group holds a list of rows, each row again
 *   a map of element id to value.
 * - A node is hidden when its parent is hidden or its own logic says so.
 * - A rule that references a hidden field sees an empty value (cascade).
 * - Inside a repeater row, rules see the fields of the same row and of all enclosing scopes.
 * - Result paths: `id` at form level, `rep[0].id` inside the first row of repeater `rep`.
 */
final class Evaluator
{
    public const OPERATORS = ['is', 'isnot', 'contains', 'gt', 'lt', 'empty', 'notempty'];

    /** @var array<string, bool> */
    private array $enabledPaths = [];
    /** @var array<string, bool> */
    private array $requiredPaths = [];

    public function __construct(private readonly Tree $tree)
    {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function evaluate(array $values): Visibility
    {
        $out = [];
        $this->enabledPaths = [];
        $this->requiredPaths = [];
        $this->collect($this->tree->rootIds(), new Scope(null, $values, null, ''), $out);
        return new Visibility($out, $this->enabledPaths, $this->requiredPaths);
    }

    /**
     * Evaluates a logic block that is not attached to a node (e.g. the condition of a notification)
     * at form level. Null or an empty rule list means "always".
     *
     * @param array<string, mixed> $values
     */
    public function conditionMet(mixed $logic, array $values): bool
    {
        return $this->logicAllows($logic, new Scope(null, $values, null, ''));
    }

    /**
     * @param list<string> $ids
     * @param array<string, bool> $out
     */
    private function collect(array $ids, Scope $scope, array &$out): void
    {
        foreach ($ids as $id) {
            $out[$scope->prefix . $id] = $this->visible($id, $scope);
            $this->enabledPaths[$scope->prefix . $id] = $this->enabled($id, $scope);
            $node = $this->tree->node($id);
            $this->requiredPaths[$scope->prefix . $id] = !empty($node['required']) ||
                (($node['logic']['action'] ?? '') === 'require' && $this->logicAllows($node['logic'], $scope));
            if ($this->tree->isRepeater($id)) {
                $rows = $scope->values[$id] ?? [];
                foreach (is_array($rows) ? array_values($rows) : [] as $index => $row) {
                    $rowScope = new Scope($id, is_array($row) ? $row : [], $scope, $scope->prefix . $id . '[' . $index . '].');
                    $this->collect($this->tree->childIds($id), $rowScope, $out);
                }
                continue;
            }
            $this->collect($this->tree->childIds($id), $scope, $out);
        }
    }

    private function resolve(?string $repeater, Scope $scope): ?Scope
    {
        $current = $scope;
        while ($current !== null) {
            if ($current->repeater === $repeater) {
                return $current;
            }
            $current = $current->parent;
        }
        return null;
    }

    private function visible(string $id, Scope $scope): bool
    {
        if (array_key_exists($id, $scope->memo)) {
            return $scope->memo[$id];
        }
        // Guard: a dependency cycle (rejected by the schema validator) resolves to hidden instead of recursing forever.
        $scope->memo[$id] = false;

        $result = true;
        $parent = $this->tree->parentOf($id);
        if ($parent !== null) {
            $parentScope = $this->resolve($this->tree->scopeOf($parent), $scope);
            $result = $parentScope !== null && $this->visible($parent, $parentScope);
        }
        if ($result) {
            $logic = $this->tree->node($id)['logic'] ?? null;
            $result = is_array($logic) && in_array($logic['action'] ?? '', ['require', 'disable'], true) ? true : $this->logicAllows($logic, $scope);
        }
        $scope->memo[$id] = $result;
        return $result;
    }

    private function enabled(string $id, Scope $scope): bool
    {
        if (array_key_exists($id, $scope->enabledMemo)) {
            return $scope->enabledMemo[$id];
        }
        $scope->enabledMemo[$id] = false;
        $parent = $this->tree->parentOf($id);
        $result = true;
        if ($parent !== null) {
            $parentScope = $this->resolve($this->tree->scopeOf($parent), $scope);
            $result = $parentScope !== null && $this->enabled($parent, $parentScope);
        }
        $logic = $this->tree->node($id)['logic'] ?? null;
        if ($result && is_array($logic) && ($logic['action'] ?? '') === 'disable') {
            $result = !$this->logicAllows($logic, $scope);
        }
        $scope->enabledMemo[$id] = $result;
        return $result;
    }

    private function logicAllows(mixed $logic, Scope $scope): bool
    {
        if (!is_array($logic) || !is_array($logic['rules'] ?? null) || $logic['rules'] === []) {
            return true;
        }
        $any = ($logic['match'] ?? 'all') === 'any';
        $matched = !$any;
        foreach ($logic['rules'] as $rule) {
            $ok = is_array($rule) && $this->ruleMatches($rule, $scope);
            if ($any && $ok) {
                $matched = true;
                break;
            }
            if (!$any && !$ok) {
                $matched = false;
                break;
            }
        }
        return ($logic['action'] ?? 'show') === 'hide' ? !$matched : $matched;
    }

    /**
     * @param array<mixed> $rule
     */
    private function ruleMatches(array $rule, Scope $scope): bool
    {
        $field = is_string($rule['field'] ?? null) ? $rule['field'] : '';
        $value = '';
        if ($field !== '' && $this->tree->isLeafData($field)) {
            $fieldScope = $this->resolve($this->tree->scopeOf($field), $scope);
            if ($fieldScope !== null && $this->visible($field, $fieldScope) && $this->enabled($field, $fieldScope)) {
                $value = $fieldScope->values[$field] ?? '';
            }
        }
        $operator = is_string($rule['op'] ?? null) ? $rule['op'] : 'is';
        $target = $rule['value'] ?? '';
        return self::matches($operator, $value, is_scalar($target) ? (string) $target : '');
    }

    public static function matches(string $operator, mixed $value, string $target): bool
    {
        $value = self::normalize($value);
        $isEmpty = is_array($value) ? $value === [] : trim($value) === '';
        $needle = self::fold($target);

        switch ($operator) {
            case 'empty':
                return $isEmpty;
            case 'notempty':
                return !$isEmpty;
            case 'is':
            case 'isnot':
                $hit = false;
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (self::fold($item) === $needle) {
                        $hit = true;
                        break;
                    }
                }
                return $operator === 'is' ? $hit : !$hit;
            case 'contains':
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (str_contains(self::fold($item), $needle)) {
                        return true;
                    }
                }
                return false;
            case 'gt':
            case 'lt':
                if (is_array($value)) {
                    return false;
                }
                $left = self::number($value);
                $right = self::number($target);
                if ($left === null || $right === null) {
                    return false;
                }
                return $operator === 'gt' ? $left > $right : $left < $right;
        }
        return false;
    }

    /**
     * @return string|list<string>
     */
    private static function normalize(mixed $value): string|array
    {
        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $list[] = is_bool($item) ? ($item ? '1' : '') : (string) $item;
                }
            }
            return $list;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /** Accepts `1.5` and the German `1,5`. */
    public static function number(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? (float) $value : null;
    }
}
