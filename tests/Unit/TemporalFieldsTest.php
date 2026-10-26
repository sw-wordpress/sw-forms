<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SWForms\Core\Validation\Birthdate;
use SWForms\Core\Validation\TemporalInterval;
use SWForms\Core\Form\StaticTypeCatalog;

final class TemporalFieldsTest extends TestCase
{
    public function testAgeAndLeapDayPolicy(): void
    {
        $today = new DateTimeImmutable('2022-02-28', new DateTimeZone('Europe/Berlin'));
        self::assertSame(17, Birthdate::age('2004-02-29', $today));
        self::assertSame(18, Birthdate::age('2004-02-29', $today->modify('+1 day')));
        self::assertSame(18, Birthdate::age('2004-02-28', $today));
        self::assertSame(0, Birthdate::age('2022-02-28', $today));
        self::assertNull(Birthdate::age('2023-02-28', $today));
        self::assertNull(Birthdate::age('2003-02-29', $today));
        self::assertSame(['min' => '2003-03-01', 'max' => '2004-02-28'], Birthdate::bounds($today, 18, 18));
        self::assertSame(['min' => '', 'max' => '2022-02-28'], Birthdate::bounds($today, null, null));
    }

    public function testAgeBoundsAgreeWithValidationAroundLeapYears(): void
    {
        foreach (['2022-02-28', '2022-03-01', '2024-02-29', '2025-01-01'] as $day) {
            $today = new DateTimeImmutable($day, new DateTimeZone('Asia/Tokyo'));
            $bounds = Birthdate::bounds($today, 18, 30);
            foreach (['1993-02-28', '1993-03-01', '1994-02-28', '1994-03-01', '2004-02-29', '2006-02-28', '2006-03-01', '2007-01-01'] as $birth) {
                $age = Birthdate::age($birth, $today);
                self::assertSame($age !== null && $age >= 18 && $age <= 30, $birth >= $bounds['min'] && $birth <= $bounds['max']);
            }
        }
    }

    public function testSharedIntervalFixtures(): void
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/interval-values.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            self::assertSame($fixture['expected'], TemporalInterval::parse($fixture['raw']));
        }
    }

    public function testIntervalValidation(): void
    {
        self::assertNull(TemporalInterval::problem(['2024-02-01', '2024-02-29'], 'date'));
        self::assertSame('date', TemporalInterval::problem(['2023-02-29', '2023-03-01'], 'date'));
        self::assertSame('required', TemporalInterval::problem(['2024-01-01', ''], 'date'));
        self::assertSame('interval_order', TemporalInterval::problem(['2024-02-02', '2024-02-01'], 'date'));
        self::assertSame('interval_order', TemporalInterval::problem(['09:00', '09:00:00'], 'time', '', '', false));
        self::assertNull(TemporalInterval::problem(['09:00:00', '09:00'], 'time'));
        self::assertSame('interval_order', TemporalInterval::problem(['23:00', '01:00'], 'time'));
        self::assertSame('time', TemporalInterval::problem(['24:00', '24:00'], 'time'));
        self::assertSame('min', TemporalInterval::problem(['09:00', '10:00'], 'time', '09:30'));
        self::assertSame('max', TemporalInterval::problem(['09:00', '10:00'], 'time', '', '09:30'));
        self::assertSame('step', TemporalInterval::problem(['09:01', '10:00'], 'time', '', '', true, 900));
        self::assertNull(TemporalInterval::problem(['09:05', '09:20'], 'time', '09:05', '', true, 900));
        foreach (['birthdate', 'date-interval', 'time-interval'] as $type) {
            self::assertTrue(StaticTypeCatalog::builtIn()->isData($type));
        }
    }
}
