<?php

declare(strict_types=1);

namespace SWForms\Core\Form;

/**
 * What the framework independent core needs to know about element types.
 *
 * Implemented by the WordPress field registry; tests use {@see StaticTypeCatalog}.
 */
interface TypeCatalog
{
    public const CONTAINER_NONE = '';
    /** Children are stored in `children` (group). */
    public const CONTAINER_CHILDREN = 'children';
    /** Children are stored in `columns[].children` (layout columns). */
    public const CONTAINER_COLUMNS = 'columns';

    public function has(string $type): bool;

    /** Whether elements of this type produce a value in the entry (group: an object). */
    public function isData(string $type): bool;

    /** One of the CONTAINER_* constants. */
    public function container(string $type): string;
}
