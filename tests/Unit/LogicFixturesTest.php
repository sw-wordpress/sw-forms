<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\StaticTypeCatalog;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;

/**
 * Runs the shared fixtures (also used by the TypeScript evaluator tests).
 */
final class LogicFixturesTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/logic/';

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string, 3: bool}>
     */
    public static function operatorCases(): array
    {
        $data = self::load('operators.json');
        $cases = [];
        foreach ($data['cases'] as $i => $case) {
            $cases[$i . ' ' . $case['op'] . ' ' . json_encode($case['value'])] = [$case['op'], $case['value'], $case['target'], $case['expected']];
        }
        return $cases;
    }

    #[DataProvider('operatorCases')]
    public function testOperator(string $op, mixed $value, string $target, bool $expected): void
    {
        self::assertSame($expected, Evaluator::matches($op, $value, $target));
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: array<string, bool>}>
     */
    public static function visibilityCases(): array
    {
        $cases = [];
        foreach (['basic.json', 'cascade.json', 'containers.json', 'repeater.json', 'contact-groups.json', 'files.json', 'media.json'] as $file) {
            $data = self::load($file);
            foreach ($data['cases'] as $case) {
                $cases[$file . ': ' . $case['name']] = [$data['fields'], $case['values'], $case['expected']];
            }
        }
        return $cases;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $values
     * @param array<string, bool>        $expected
     */
    #[DataProvider('visibilityCases')]
    public function testVisibility(array $fields, array $values, array $expected): void
    {
        $result = (new Evaluator(Tree::build($fields, StaticTypeCatalog::builtIn())))->evaluate($values)->toArray();
        foreach ($expected as $path => $visible) {
            self::assertArrayHasKey($path, $result, "path $path missing");
            self::assertSame($visible, $result[$path], "path $path");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $file): array
    {
        $data = json_decode((string) file_get_contents(self::DIR . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }
}
