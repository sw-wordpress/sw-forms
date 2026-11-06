<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Validation\TemporalInterval;

final class TimePickerFixturesTest extends TestCase
{
    public function testBrowserOptionsMatchServerStepAndBounds(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/time-options.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            foreach ($case['allowed'] as $value) {
                self::assertNull(TemporalInterval::problem([$value, $value], 'time', $case['min'], $case['max'], true, $case['step']));
            }
            self::assertSame('step', TemporalInterval::problem([$case['rejected'], $case['rejected']], 'time', $case['min'], $case['max'], true, $case['step']));
        }
    }
}
