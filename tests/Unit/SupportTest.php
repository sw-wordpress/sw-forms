<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Security\SubmissionToken;
use SWForms\Core\Template\MergeTags;
use SWForms\Core\Validation\Rules;

final class SupportTest extends TestCase
{
    public function testMergeTagsReplaceKnownAndKeepUnknown(): void
    {
        $out = MergeTags::replace(
            'Hi {field:first}, entry {entry:id} {unknown} {all_fields} {field:a.b}',
            static fn (string $tag, string $arg): ?string => match ($tag) {
                'field' => strtoupper($arg),
                'entry' => '42',
                'all_fields' => '[all]',
                default => null,
            }
        );
        self::assertSame('Hi FIRST, entry 42 {unknown} [all] A.B', $out);
        self::assertSame([['field', 'x'], ['site', '']], MergeTags::tags('{field:x} and {site}'));
    }

    public function testRules(): void
    {
        self::assertTrue(Rules::email('a@example.org'));
        self::assertFalse(Rules::email('a@'));
        self::assertTrue(Rules::url('https://example.org/x'));
        self::assertFalse(Rules::url('javascript:alert(1)'));
        self::assertTrue(Rules::tel('+49 (30) 123-45'));
        self::assertFalse(Rules::tel('call me'));
        self::assertTrue(Rules::date('2024-02-29'));
        self::assertFalse(Rules::date('2023-02-29'));
        self::assertSame(2.5, Rules::number('2,5'));
        self::assertTrue(Rules::pattern('[A-Z]{2}\d+', 'DE123'));
        self::assertFalse(Rules::pattern('[A-Z]{2}', 'DE1'));
        self::assertTrue(Rules::pattern('a~b', 'a~b'));
        self::assertNull(Rules::pattern('(', 'x'));
        self::assertSame(3, Rules::length('äöü'));
    }

    public function testTemporalFormatsAndStepValidation(): void
    {
        foreach (['00:00', '23:59:59', '09:30'] as $value) {
            self::assertTrue(Rules::time($value));
        }
        foreach (['24:00', '12:60', '9:30', '12:30:60', '12:00Z'] as $value) {
            self::assertFalse(Rules::time($value));
        }
        self::assertTrue(Rules::dateTime('2024-02-29T23:59:59'));
        self::assertFalse(Rules::dateTime('2023-02-29T12:00'));
        self::assertFalse(Rules::dateTime('2024-02-29T24:00'));
        self::assertFalse(Rules::dateTime('2024-02-29T12:00+01:00'));
        self::assertTrue(Rules::step(0.3, 0.1));
        self::assertTrue(Rules::step(1.3, 0.5, 0.3));
        self::assertFalse(Rules::step(1.4, 0.5, 0.3));
        self::assertFalse(Rules::step(1.0, 0.0));
        self::assertFalse(Rules::step(INF, 0.1));
    }

    public function testSubmissionToken(): void
    {
        $token = new SubmissionToken('secret');
        $value = $token->issue(5, 1000, 'abc');

        self::assertSame(['status' => 'ok', 'id' => 'abc', 'issued' => 1000], $token->verify($value, 5, 1010, 3, 86400));
        self::assertSame('too_fast', $token->verify($value, 5, 1001, 3, 86400)['status']);
        self::assertSame('expired', $token->verify($value, 5, 1000 + 86401, 3, 86400)['status']);
        self::assertSame('invalid', $token->verify($value, 6, 1010, 3, 86400)['status']);
        self::assertSame('invalid', (new SubmissionToken('other'))->verify($value, 5, 1010, 3, 86400)['status']);
        self::assertSame('invalid', $token->verify('garbage', 5, 1010, 3, 86400)['status']);
    }
}
