<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\DataBuilder;
use SWForms\Core\Form\StaticTypeCatalog;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;

final class DataBuilderTest extends TestCase
{
    public function testBuildsNestedShapeAndOmitsHiddenFields(): void
    {
        $fields = [
            ['id' => 'f1', 'type' => 'input', 'key' => 'email'],
            ['id' => 'cols', 'type' => 'columns', 'columns' => [
                ['id' => 'c1', 'width' => 6, 'children' => [['id' => 'f2', 'type' => 'input', 'key' => 'first']]],
                ['id' => 'c2', 'width' => 6, 'children' => [['id' => 'f3', 'type' => 'checkbox', 'key' => 'topics']]],
            ]],
            ['id' => 'g', 'type' => 'group', 'key' => 'address', 'children' => [
                ['id' => 'f4', 'type' => 'input', 'key' => 'city'],
            ]],
            ['id' => 'rep', 'type' => 'group', 'key' => 'people', 'settings' => ['repeatable' => true], 'children' => [
                ['id' => 'f5', 'type' => 'input', 'key' => 'name'],
                ['id' => 'f6', 'type' => 'input', 'key' => 'age', 'logic' => [
                    'action' => 'show', 'match' => 'all', 'rules' => [['field' => 'f5', 'op' => 'is', 'value' => 'kid']],
                ]],
            ]],
        ];
        $values = [
            'f1' => 'a@example.org',
            'f2' => 'Max',
            'f3' => ['news', 'events'],
            'f4' => 'Berlin',
            'rep' => [['f5' => 'kid', 'f6' => '7'], ['f5' => 'adult', 'f6' => '40']],
        ];
        $tree = Tree::build($fields, StaticTypeCatalog::builtIn());
        $visibility = (new Evaluator($tree))->evaluate($values);
        $builder = new DataBuilder($tree);

        self::assertSame([
            'email' => 'a@example.org',
            'first' => 'Max',
            'topics' => ['news', 'events'],
            'address' => ['city' => 'Berlin'],
            'people' => [['name' => 'kid', 'age' => '7'], ['name' => 'adult']],
        ], $builder->build($values, $visibility));

        self::assertSame([
            ['field' => 'f1', 'row' => '', 'value' => 'a@example.org'],
            ['field' => 'f2', 'row' => '', 'value' => 'Max'],
            ['field' => 'f3', 'row' => '', 'value' => 'news, events'],
            ['field' => 'f4', 'row' => '', 'value' => 'Berlin'],
            ['field' => 'f5', 'row' => 'rep[0].', 'value' => 'kid'],
            ['field' => 'f6', 'row' => 'rep[0].', 'value' => '7'],
            ['field' => 'f5', 'row' => 'rep[1].', 'value' => 'adult'],
        ], $builder->flatten($values, $visibility));

        $upper = static fn (string $id, array $element, mixed $value): string => strtoupper(DataBuilder::stringify($value));
        self::assertSame('NEWS, EVENTS', $builder->flatten($values, $visibility, $upper)[2]['value']);
    }
}
