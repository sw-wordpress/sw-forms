<?php

declare(strict_types=1);

namespace SWForms\Core\Calculation;

/** Bounded arithmetic shared with the browser through fixture tests; no executable expressions. */
final class Arithmetic
{
    /** @param list<float> $values */
    public static function evaluate(string $operation, array $values, int $decimals = 2): ?string
    {
        if ($values === [] || !in_array($operation, ['sum', 'product', 'difference', 'quotient'], true)) {
            return null;
        }
        $result = array_shift($values);
        foreach ($values as $value) {
            if ($operation === 'quotient' && $value == 0.0) {
                return null;
            }
            $result = match ($operation) {
                'sum' => $result + $value,
                'product' => $result * $value,
                'difference' => $result - $value,
                default => $result / $value,
            };
        }
        if (!is_finite($result) || abs($result) > 1e12) {
            return null;
        }
        return number_format(round($result, max(0, min(8, $decimals)), PHP_ROUND_HALF_UP), max(0, min(8, $decimals)), '.', '');
    }
}
