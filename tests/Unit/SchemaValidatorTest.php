<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\SchemaValidator;
use SWForms\Core\Form\StaticTypeCatalog;

final class SchemaValidatorTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $fields
     * @return list<string> "code@path"
     */
    private function errors(array $fields): array
    {
        $errors = (new SchemaValidator(StaticTypeCatalog::builtIn()))->validate(['fields' => $fields]);
        return array_map(static fn (array $e): string => $e['code'] . '@' . $e['path'], $errors);
    }

    public function testValidDocumentPasses(): void
    {
        $fields = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/logic/containers.json'), true)['fields'];
        self::assertSame([], $this->errors($fields));
    }

    public function testRejectsInvalidDocument(): void
    {
        self::assertSame(['invalid_document@'], array_map(
            static fn (array $e): string => $e['code'] . '@' . $e['path'],
            (new SchemaValidator(StaticTypeCatalog::builtIn()))->validate(['fields' => 'nope'])
        ));
    }

    public function testDuplicateIdsAndUnknownTypes(): void
    {
        $errors = $this->errors([
            ['id' => 'a', 'type' => 'input', 'key' => 'a'],
            ['id' => 'a', 'type' => 'input', 'key' => 'b'],
            ['id' => 'x', 'type' => 'rocket', 'key' => 'x'],
            ['id' => 'bad id', 'type' => 'input', 'key' => 'y'],
        ]);
        self::assertContains('duplicate_id@a', $errors);
        self::assertContains('unknown_type@x', $errors);
        self::assertContains('invalid_id@', $errors);
    }

    public function testKeysAreUniquePerDataObjectAndColumnsAreTransparent(): void
    {
        $errors = $this->errors([
            ['id' => 'a', 'type' => 'input', 'key' => 'name'],
            ['id' => 'cols', 'type' => 'columns', 'columns' => [
                ['id' => 'c1', 'width' => 6, 'children' => [['id' => 'b', 'type' => 'input', 'key' => 'name']]],
            ]],
            ['id' => 'g', 'type' => 'group', 'key' => 'group', 'children' => [
                ['id' => 'c', 'type' => 'input', 'key' => 'name'],
            ]],
            ['id' => 'd', 'type' => 'input', 'key' => '1bad'],
        ]);
        self::assertSame(['duplicate_key@b', 'invalid_key@d'], $errors);
    }

    public function testColumnConstraints(): void
    {
        $errors = $this->errors([
            ['id' => 'cols', 'type' => 'columns', 'columns' => []],
            ['id' => 'cols2', 'type' => 'columns', 'columns' => [['id' => 'c', 'width' => 13, 'children' => []]]],
        ]);
        self::assertSame(['invalid_columns@cols', 'invalid_width@c'], $errors);
    }

    public function testRuleValidation(): void
    {
        $rule = static fn (string $field, string $op = 'is'): array => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => $field, 'op' => $op, 'value' => 'x']]];
        $errors = $this->errors([
            ['id' => 'a', 'type' => 'input', 'key' => 'a', 'logic' => $rule('a')],
            ['id' => 'b', 'type' => 'input', 'key' => 'b', 'logic' => $rule('missing')],
            ['id' => 'c', 'type' => 'input', 'key' => 'c', 'logic' => $rule('a', 'like')],
            ['id' => 'rep', 'type' => 'group', 'key' => 'rep', 'settings' => ['repeatable' => true], 'children' => [
                ['id' => 'inner', 'type' => 'input', 'key' => 'inner', 'logic' => $rule('a')],
            ]],
            ['id' => 'd', 'type' => 'input', 'key' => 'd', 'logic' => $rule('inner')],
            ['id' => 'e', 'type' => 'input', 'key' => 'e', 'logic' => $rule('rep')],
        ]);
        self::assertSame([
            'rule_self_reference@a',
            'rule_unknown_field@b',
            'invalid_operator@c',
            'rule_out_of_scope@d',
            'rule_unknown_field@e',
        ], $errors);
    }

    public function testDetectsCycles(): void
    {
        $rule = static fn (string $field): array => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => $field, 'op' => 'notempty']]];
        $errors = $this->errors([
            ['id' => 'a', 'type' => 'input', 'key' => 'a', 'logic' => $rule('b')],
            ['id' => 'b', 'type' => 'input', 'key' => 'b', 'logic' => $rule('a')],
            ['id' => 'g', 'type' => 'group', 'key' => 'g', 'logic' => $rule('inside'), 'children' => [
                ['id' => 'inside', 'type' => 'input', 'key' => 'inside'],
            ]],
            ['id' => 'ok', 'type' => 'input', 'key' => 'ok', 'logic' => $rule('a')],
        ]);
        sort($errors);
        self::assertSame(['logic_cycle@a', 'logic_cycle@b', 'logic_cycle@g', 'logic_cycle@inside'], $errors);
    }
}
