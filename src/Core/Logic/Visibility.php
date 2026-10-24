<?php

declare(strict_types=1);

namespace SWForms\Core\Logic;

/**
 * Result of {@see Evaluator::evaluate()}: visibility per node path.
 */
final class Visibility
{
    /**
     * @param array<string, bool> $paths
     */
    public function __construct(private readonly array $paths)
    {
    }

    /**
     * @param string $prefix Row prefix such as `rep[0].`, empty at form level.
     */
    public function isVisible(string $id, string $prefix = ''): bool
    {
        return $this->paths[$prefix . $id] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->paths;
    }
}
