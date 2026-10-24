<?php

declare(strict_types=1);

namespace SWForms\Core\Form;

/**
 * Index over a form's element tree.
 *
 * Layout columns (`columns[]` of a columns element) are indexed as pseudo nodes of type
 * {@see Tree::COLUMN_TYPE} because they carry their own id and conditional logic.
 * The "scope" of a node is the id of the nearest enclosing repeatable group (null = form level);
 * a repeater itself belongs to the scope it is placed in.
 */
final class Tree
{
    public const COLUMN_TYPE = '@column';

    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];
    /** @var array<string, string|null> */
    private array $parent = [];
    /** @var array<string, string|null> */
    private array $scope = [];
    /** @var array<string, list<string>> */
    private array $children = [];
    /** @var list<string> */
    private array $rootIds = [];
    /** @var list<string> */
    private array $duplicates = [];

    private function __construct(private readonly TypeCatalog $types)
    {
    }

    /**
     * @param list<mixed> $elements
     */
    public static function build(array $elements, TypeCatalog $types): self
    {
        $tree = new self($types);
        $tree->rootIds = $tree->add($elements, null, null);
        return $tree;
    }

    /**
     * @param list<mixed> $elements
     * @return list<string>
     */
    private function add(array $elements, ?string $parent, ?string $scope): array
    {
        $ids = [];
        foreach ($elements as $element) {
            if (!is_array($element) || !isset($element['id']) || !is_string($element['id']) || $element['id'] === '') {
                continue;
            }
            $id = $element['id'];
            if (isset($this->nodes[$id])) {
                $this->duplicates[] = $id;
                continue;
            }
            $this->nodes[$id] = $element;
            $this->parent[$id] = $parent;
            $this->scope[$id] = $scope;
            $this->children[$id] = [];
            $ids[] = $id;

            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            $container = $this->types->container($type);
            if ($container === TypeCatalog::CONTAINER_CHILDREN) {
                $childScope = self::repeatable($element) ? $id : $scope;
                $this->children[$id] = $this->add(self::listOf($element['children'] ?? []), $id, $childScope);
            } elseif ($container === TypeCatalog::CONTAINER_COLUMNS) {
                $columns = [];
                foreach (self::listOf($element['columns'] ?? []) as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    $column['type'] = self::COLUMN_TYPE;
                    $columns[] = $column;
                }
                $this->children[$id] = $this->add($columns, $id, $scope);
            } elseif ($type === self::COLUMN_TYPE) {
                $this->children[$id] = $this->add(self::listOf($element['children'] ?? []), $id, $scope);
            }
        }
        return $ids;
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<string, mixed> $element
     */
    public static function repeatable(array $element): bool
    {
        $settings = $element['settings'] ?? null;
        return is_array($settings) && ($settings['repeatable'] ?? false) === true;
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function node(string $id): array
    {
        return $this->nodes[$id] ?? [];
    }

    /**
     * @return list<string> All indexed ids in document order.
     */
    public function ids(): array
    {
        return array_keys($this->nodes);
    }

    /**
     * @return list<string>
     */
    public function rootIds(): array
    {
        return $this->rootIds;
    }

    /**
     * @return list<string>
     */
    public function childIds(string $id): array
    {
        return $this->children[$id] ?? [];
    }

    public function parentOf(string $id): ?string
    {
        return $this->parent[$id] ?? null;
    }

    public function scopeOf(string $id): ?string
    {
        return $this->scope[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function duplicates(): array
    {
        return $this->duplicates;
    }

    public function type(string $id): string
    {
        $type = $this->nodes[$id]['type'] ?? '';
        return is_string($type) ? $type : '';
    }

    public function isColumn(string $id): bool
    {
        return $this->type($id) === self::COLUMN_TYPE;
    }

    public function isData(string $id): bool
    {
        return !$this->isColumn($id) && $this->types->isData($this->type($id));
    }

    public function isRepeater(string $id): bool
    {
        return $this->types->container($this->type($id)) === TypeCatalog::CONTAINER_CHILDREN
            && self::repeatable($this->node($id));
    }

    /** Whether the node produces a scalar or list value (not a group object). */
    public function isLeafData(string $id): bool
    {
        return $this->isData($id) && $this->types->container($this->type($id)) === TypeCatalog::CONTAINER_NONE;
    }

    public function isDescendant(string $id, string $ancestor): bool
    {
        $current = $this->parentOf($id);
        while ($current !== null) {
            if ($current === $ancestor) {
                return true;
            }
            $current = $this->parentOf($current);
        }
        return false;
    }

    /**
     * Whether a node in scope $inner can see values of scope $outer
     * (same scope or $outer encloses $inner).
     */
    public function scopeEncloses(?string $outer, ?string $inner): bool
    {
        $current = $inner;
        while (true) {
            if ($current === $outer) {
                return true;
            }
            if ($current === null) {
                return false;
            }
            $current = $this->scopeOf($current);
        }
    }
}
