<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Calculation\Arithmetic;

final class CalculationTest extends TestCase
{
    public function testSharedArithmeticFixtures(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/calculations.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            self::assertSame($case['expected'], Arithmetic::evaluate($case['operation'], array_map('floatval', $case['values']), $case['decimals']), (string) json_encode($case));
        }
    }
}
