<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SWForms\Core\Validation\TemporalBound;

final class TemporalBoundTest extends TestCase
{
    public function testRelativeBoundsUseExplicitTimezoneAndClock(): void
    {
        $now = new DateTimeImmutable('2024-02-28 23:45:59', new DateTimeZone('Europe/Berlin'));
        self::assertSame('2024-02-29', TemporalBound::resolve('today + 1 day', 'date', $now));
        self::assertSame('2024-02-21', TemporalBound::resolve('today - 1 week', 'date', $now));
        self::assertSame('2024-02-28T00:00', TemporalBound::resolve('today', 'datetime-local', $now));
        self::assertSame('2024-02-28T23:45', TemporalBound::resolve('now', 'datetime-local', $now));
        self::assertSame('2024-02-29T01:45', TemporalBound::resolve('now + 2 hours', 'datetime-local', $now));
        self::assertSame('2024-02-29T00:00', TemporalBound::resolve(' NOW + 15 MINUTES ', 'datetime-local', $now));
        self::assertSame('2025-02-28', TemporalBound::resolve('today + 1 year', 'date', $now));
        $beforeMidnight = $now->setTimezone(new DateTimeZone('Pacific/Honolulu'));
        $afterMidnight = $now->setTimezone(new DateTimeZone('Asia/Tokyo'));
        self::assertNotSame(TemporalBound::resolve('today', 'date', $beforeMidnight), TemporalBound::resolve('today', 'date', $afterMidnight));
    }

    public function testLiteralAndUnrecognizedExpressionsRemainUnchanged(): void
    {
        $now = new DateTimeImmutable('2024-01-01');
        foreach (['2024-01-03', 'tomorrow', 'today + 10000 days', 'today + 2 days + 3 hours', 'now Europe/Berlin', ''] as $value) {
            self::assertSame($value, TemporalBound::resolve($value, 'date', $now));
        }
        self::assertSame('now', TemporalBound::resolve('now', 'time', $now));
    }
}
