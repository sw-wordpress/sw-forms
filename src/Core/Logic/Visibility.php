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
     * @param array<string, bool> $enabled
     * @param array<string, bool> $required
     */
    public function __construct(private readonly array $paths, private readonly array $enabled = [], private readonly array $required = [])
    {
    }

    /**
     * @param string $prefix Row prefix such as `rep[0].`, empty at form level.
     */
    public function isVisible(string $id, string $prefix = ''): bool
    {
        return $this->paths[$prefix . $id] ?? false;
    }

    public function isEnabled(string $id, string $prefix = ''): bool
    {
        return $this->enabled[$prefix . $id] ?? true;
    }

    public function isActive(string $id, string $prefix = ''): bool
    {
        return $this->isVisible($id, $prefix) && $this->isEnabled($id, $prefix);
    }

    public function isRequired(string $id, string $prefix = ''): bool
    {
        return $this->required[$prefix . $id] ?? false;
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return $this->paths;
    }
}
