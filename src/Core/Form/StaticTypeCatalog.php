<?php

declare(strict_types=1);

namespace SWForms\Core\Form;

/**
 * Type catalog backed by a plain map. Used by tests and as the source of the built-in type facts.
 */
final class StaticTypeCatalog implements TypeCatalog
{
    /**
     * @param array<string, array{data: bool, container: string}> $types
     */
    public function __construct(private readonly array $types)
    {
    }

    /** Catalog of the built-in element types. */
    public static function builtIn(): self
    {
        return new self([
            'birthdate' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'date-interval' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'time-interval' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'input' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'textarea' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'checkbox' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'radio' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'select' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'range' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'rating' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'scale' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'color' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'tags' => ['data' => true, 'container' => self::CONTAINER_NONE],
            'name' => ['data' => true, 'container' => self::CONTAINER_CHILDREN],
            'address' => ['data' => true, 'container' => self::CONTAINER_CHILDREN],
            'group' => ['data' => true, 'container' => self::CONTAINER_CHILDREN],
            'columns' => ['data' => false, 'container' => self::CONTAINER_COLUMNS],
        ]);
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function isData(string $type): bool
    {
        return $this->types[$type]['data'] ?? false;
    }

    public function container(string $type): string
    {
        return $this->types[$type]['container'] ?? self::CONTAINER_NONE;
    }
}
