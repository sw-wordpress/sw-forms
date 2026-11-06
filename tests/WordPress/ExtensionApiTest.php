<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\SettingsNormalizer;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Frontend\FormView;
use SWForms\WordPress\Template\MergeTagRegistry;
use SWForms\WordPress\Validation\ValidatorRegistry;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExtensionApiTest extends TestCase
{
    public function testExampleAddonRunsThroughThePublishedFormPipeline(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/sw-forms-example/sw-forms-example.php';
        \SWFormsExample\boot();
        \SWFormsExample\register_assets();
        wp_set_current_user(1);
        $forms = FormService::create_default();
        $id = $forms->create('Extension API test');
        try {
            $document = [
                'fields' => [['id' => 'ref', 'type' => 'example-reference', 'key' => 'reference', 'label' => 'Reference', 'required' => true,
                    'settings' => ['related' => 'ref', 'metadata' => [['key' => 'team', 'value' => 'Test']], 'steps' => [['title' => '<b>One</b>', 'enabled' => true, 'unknown' => 'drop']]]]],
                'settings' => ['notifications' => [], 'confirmation' => ['type' => 'message', 'message' => '{example_reference:reference}']],
            ];
            self::assertSame([], $forms->save($id, 'Extension API test', $document)['errors']);
            $stored = swf_get_form($id);
            self::assertSame([['title' => 'One', 'enabled' => true]], $stored['fields'][0]['settings']['steps']);
            self::assertSame('ref', $stored['fields'][0]['settings']['related']);
            self::assertStringContainsString('name="swf[ref]"', FormView::render($id));
            self::assertSame('This field is required.', swf_submit($id, ['ref' => ''])->errors['ref']);
            self::assertSame('Use REF- followed by three digits.', swf_submit($id, ['ref' => 'bad'])->errors['ref']);
            $result = swf_submit($id, ['ref' => 'REF-123']);
            self::assertSame('success', $result->status);
            self::assertSame('REF-123', $result->message);
            $entry = (new \SWForms\WordPress\Entries\EntryRepository())->find($result->entry_id);
            self::assertSame('REF-123', $entry['data']['reference']);
            $log = (new \SWForms\WordPress\Entries\EntryRepository())->logs($result->entry_id);
            self::assertSame('example', $log[0]['type']);
            self::assertSame('success', $log[0]['status']);
            self::assertSame(1, did_action('swf_register_validators'));
            self::assertSame(1, did_action('swf_register_merge_tags'));
            self::assertSame('sw-forms-example', FieldRegistry::instance()->get('example-reference')->builder_script());
            self::assertContains('sw-forms-builder-api', wp_scripts()->registered['sw-forms-example']->deps);

            add_filter('swf_merge_tag', static fn($value, $tag) => $tag === 'example_reference' ? '<b>&</b>' : $value, 10, 2);
            self::assertSame('&lt;b&gt;&amp;&lt;/b&gt;', swf_submit($id, ['ref' => 'REF-456'])->message);
            add_filter('swf_validation_errors', static fn($errors) => $errors + ['ref' => 'Legacy validation']);
            self::assertSame('Legacy validation', swf_submit($id, ['ref' => 'REF-789'])->errors['ref']);
        } finally {
            (new FormRepository())->delete($id);
        }
    }

    public function testCustomValidationUsesVisibilityAndRepeaterErrorPaths(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/sw-forms-example/sw-forms-example.php';
        \SWFormsExample\boot();
        $forms = FormService::create_default();
        $id = $forms->create('Extension validation scopes');
        try {
            $doc = ['fields' => [
                ['id' => 'toggle', 'key' => 'toggle', 'type' => 'checkbox', 'settings' => ['mode' => 'single']],
                ['id' => 'ref', 'key' => 'reference', 'type' => 'example-reference', 'logic' => [
                    'action' => 'show', 'match' => 'all', 'rules' => [['field' => 'toggle', 'op' => 'is', 'value' => '1']],
                ]],
                ['id' => 'rows', 'key' => 'rows', 'type' => 'group', 'settings' => ['repeatable' => true], 'children' => [
                    ['id' => 'child', 'key' => 'child', 'type' => 'example-reference'],
                ]],
            ], 'settings' => ['notifications' => []]];
            self::assertSame([], $forms->save($id, 'Extension validation scopes', $doc)['errors']);
            $result = swf_submit($id, ['toggle' => '', 'ref' => 'invalid hidden', 'rows' => [['child' => 'bad']]]);
            self::assertSame(['rows[0].child' => 'Use REF- followed by three digits.'], $result->errors);
            $result = swf_submit($id, ['toggle' => '', 'ref' => 'invalid hidden', 'rows' => [['child' => 'REF-123']]]);
            self::assertSame('success', $result->status);
            $result = swf_submit($id, ['toggle' => '1', 'ref' => 'bad', 'rows' => [['child' => 'REF-123']]]);
            self::assertArrayHasKey('ref', $result->errors);
        } finally {
            (new FormRepository())->delete($id);
        }
    }

    public function testStructuredSettingsAreSanitizedAndBounded(): void
    {
        $schema = [
            ['name' => 'field', 'type' => 'field-picker', 'default' => ''],
            ['name' => 'pairs', 'type' => 'key-value', 'maxItems' => 1],
            ['name' => 'rows', 'type' => 'repeater', 'maxItems' => 2, 'fields' => [
                ['name' => 'title', 'type' => 'text', 'default' => 'New'],
                ['name' => 'count', 'type' => 'number', 'min' => 1, 'max' => 5, 'integer' => true, 'default' => 1],
            ]],
        ];
        $out = SettingsNormalizer::normalize($schema, [
            'field' => 'invalid.id', 'pairs' => [['key' => '<b>A</b>', 'value' => '<i>B</i>'], ['key' => 'C', 'value' => 'D']],
            'rows' => [['title' => '<script>bad</script>Hello', 'count' => 9, 'extra' => 'drop'], ['count' => -1], ['count' => 3]],
        ]);
        self::assertSame('', $out['field']);
        self::assertSame([['key' => 'A', 'value' => 'B']], $out['pairs']);
        self::assertSame([['title' => 'Hello', 'count' => 5], ['title' => 'New', 'count' => 1]], $out['rows']);
        self::assertSame(['field' => '', 'pairs' => [], 'rows' => []], SettingsNormalizer::normalize($schema, ['field' => [], 'pairs' => 'bad', 'rows' => 'bad']));
        $nested = ['name' => 'rows', 'type' => 'repeater', 'default' => []];
        $value = [];
        for ($i = 0; $i < 8; $i++) {
            $nested = ['name' => 'rows', 'type' => 'repeater', 'fields' => [$nested]];
            $value = [['rows' => $value]];
        }
        $result = SettingsNormalizer::normalize([$nested], ['rows' => $value]);
        for ($i = 0; $i < 4; $i++) {
            $result = $result['rows'][0];
        }
        self::assertSame([], $result['rows']);
    }

    public function testRegistriesReplaceDuplicateNamesAndIgnoreInvalidNames(): void
    {
        $validators = new ValidatorRegistry();
        $validator = new class () implements \SWForms\WordPress\Validation\ValidatorInterface {
            public string $name = 'custom';
            public function slug(): string
            {
                return $this->name;
            }
            public function supports(array $element): bool
            {
                return ($element['type'] ?? '') === 'custom';
            }
            public function validate(mixed $value, array $element): array
            {
                return [['code' => 'pattern']];
            }
        };
        $validators->register($validator);
        $replacement = clone $validator;
        $validators->register($replacement);
        $invalid = clone $validator;
        $invalid->name = 'Invalid!';
        $validators->register($invalid);
        self::assertSame(['custom' => $replacement], $validators->all());
        self::assertSame([], $validators->validate('x', ['type' => 'input']));
        self::assertSame([['code' => 'pattern']], $validators->validate('x', ['type' => 'custom']));
        $tags = new MergeTagRegistry();
        $tag = new class () implements \SWForms\WordPress\Template\MergeTagInterface {
            public string $tag = 'custom';
            public function name(): string
            {
                return $this->tag;
            }
            public function resolve(string $argument, \SWForms\WordPress\Submission\Submission $submission): ?string
            {
                return null;
            }
        };
        $tags->register($tag);
        $replacementTag = clone $tag;
        $tags->register($replacementTag);
        $invalidTag = clone $tag;
        $invalidTag->tag = 'bad-name';
        $tags->register($invalidTag);
        self::assertSame(['custom' => $replacementTag], $tags->all());
    }
}
