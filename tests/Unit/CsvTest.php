<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Export\Csv;

final class CsvTest extends TestCase
{
    public function testProtectsFormulaCellsWithoutLosingQuotesOrUnicode(): void
    {
        $stream = fopen('php://temp', 'w+b');
        Csv::row($stream, ['=1+1', "\t@SUM(A1)", ' +cmd', '-2', 'Mäx, "test"', 'safe']);
        rewind($stream);
        self::assertSame(["'=1+1", "'\t@SUM(A1)", "' +cmd", "'-2", 'Mäx, "test"', 'safe'], fgetcsv($stream, 0, ',', '"', ''));
        fclose($stream);
    }

    public function testNestedGroupsFlattenButRepeaterAndFileListsRemainJson(): void
    {
        self::assertSame(['data.person.email' => 'max@example.org', 'data.people' => '[{"name":"Anna"}]', 'data.ok' => '0'], Csv::flatten(['person' => ['email' => 'max@example.org'], 'people' => [['name' => 'Anna']], 'ok' => false]));
    }
}
