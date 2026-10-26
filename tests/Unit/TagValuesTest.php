<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Validation\TagValues;
use SWForms\Core\Form\StaticTypeCatalog;

final class TagValuesTest extends TestCase
{
    public function testSharedFixtures(): void
    {
        $fixtures = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/tag-values.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            self::assertSame($fixture['expected'], TagValues::parse($fixture['raw']));
        }
    }
    public function testBoundsRetainEnoughToRejectOverflow(): void
    {
        self::assertCount(101, TagValues::parse(implode(',', range(0, 200))));
        self::assertSame(201, mb_strlen(TagValues::parse(str_repeat('😀', 300))[0]));
    }
    public function testCatalogIncludesNewScalarTypes(): void
    {
        foreach (['range', 'rating', 'scale', 'color', 'tags'] as $type) {
            self::assertTrue(StaticTypeCatalog::builtIn()->has($type));
            self::assertTrue(StaticTypeCatalog::builtIn()->isData($type));
            self::assertSame('', StaticTypeCatalog::builtIn()->container($type));
        }
    }
}
