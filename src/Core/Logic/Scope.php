<?php

declare(strict_types=1);

namespace SWForms\Core\Logic;

/**
 * Evaluation scope: the form itself or one row of a repeatable group.
 *
 * @internal
 */
final class Scope
{
    /** @var array<string, bool> Memoised visibility of the nodes of this scope. */
    public array $memo = [];
    /** @var array<string, bool> */
    public array $enabledMemo = [];

    /**
     * @param string|null          $repeater Repeater id, null for the form level.
     * @param array<string, mixed> $values   Values of this scope keyed by element id.
     */
    public function __construct(
        public readonly ?string $repeater,
        public readonly array $values,
        public readonly ?Scope $parent,
        public readonly string $prefix,
    ) {
    }
}
