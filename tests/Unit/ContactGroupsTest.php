<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\DataBuilder;
use SWForms\Core\Form\SchemaValidator;
use SWForms\Core\Form\StaticTypeCatalog;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;

final class ContactGroupsTest extends TestCase
{
    public function testContactObjectsUseExistingTreeAndDataContracts(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/logic/contact-groups.json'), true, 512, JSON_THROW_ON_ERROR);
        $types = StaticTypeCatalog::builtIn();
        self::assertSame([], (new SchemaValidator($types))->validate(['fields' => $fixture['fields']]));
        $tree = Tree::build($fixture['fields'], $types);
        $values = $fixture['cases'][1]['values'];
        $visibility = (new Evaluator($tree))->evaluate($values);
        $data = (new DataBuilder($tree))->build($values, $visibility);
        self::assertSame(['given_name' => 'Ada', 'family_name' => 'Lovelace'], $data['person']);
        self::assertSame(['street' => 'Main', 'city' => 'Berlin'], $data['location']);
        self::assertSame([['given_name' => 'One', 'title' => 'Dr'], ['given_name' => '']], $data['people']);
        $values['show'] = false;
        self::assertArrayNotHasKey('location', (new DataBuilder($tree))->build($values, (new Evaluator($tree))->evaluate($values)));
    }
}
