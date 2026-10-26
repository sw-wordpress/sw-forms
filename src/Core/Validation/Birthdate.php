<?php

declare(strict_types=1);

namespace SWForms\Core\Validation;

use DateTimeImmutable;

/** Age in completed calendar years; leap-day birthdays advance on March 1 in non-leap years. */
final class Birthdate
{
    public static function age(string $birthdate, DateTimeImmutable $today): ?int
    {
        if (!Rules::date($birthdate) || $birthdate > $today->format('Y-m-d')) {
            return null;
        }
        $years = (int) $today->format('Y') - (int) substr($birthdate, 0, 4);
        return $years - ($today->format('m-d') < substr($birthdate, 5) ? 1 : 0);
    }

    /** @return array{min: string, max: string} */
    public static function bounds(DateTimeImmutable $today, ?int $minAge, ?int $maxAge): array
    {
        return [
            'min' => $maxAge === null ? '' : self::anniversary($today, $maxAge + 1)->add(new \DateInterval('P1D'))->format('Y-m-d'),
            'max' => self::anniversary($today, $minAge ?? 0)->format('Y-m-d'),
        ];
    }

    private static function anniversary(DateTimeImmutable $today, int $years): DateTimeImmutable
    {
        $year = max(1, (int) $today->format('Y') - $years);
        $month = (int) $today->format('m');
        $day = (int) $today->format('d');
        while (!checkdate($month, $day, $year)) {
            --$day;
        }
        return $today->setDate($year, $month, $day)->setTime(0, 0);
    }
}
