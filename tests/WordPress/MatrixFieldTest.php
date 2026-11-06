<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\DataBuilder;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\MatrixField;
use SWForms\WordPress\Frontend\RenderContext;
use SWForms\WordPress\Frontend\Renderer;
use SWForms\WordPress\Submission\SubmissionValidator;
use SWForms\WordPress\Submission\ValueCollector;

final class MatrixFieldTest extends TestCase
{
    private function element(array $settings = [], bool $required = false): array
    {
        $type = FieldRegistry::instance()->get('matrix');
        return ['id' => 'survey', 'type' => 'matrix', 'key' => 'feedback', 'label' => 'Experience <script>', 'description' => 'One per question', 'required' => $required, 'settings' => $type->normalize_settings($settings)];
    }

    public function testRegistrationLikertDefaultsAndBoundedSettings(): void
    {
        $type = FieldRegistry::instance()->get('matrix');
        self::assertInstanceOf(MatrixField::class, $type);
        self::assertSame('matrix', $type->value_kind([]));
        self::assertSame('choice', $type->category());
        self::assertNotEmpty($type->label());
        self::assertNotEmpty($type->icon());
        self::assertCount(5, $type->default_settings()['options']);
        self::assertSame([], $type->default_value([]));
        self::assertSame($type->default_settings(), $type->normalize_settings(['rows' => [], 'options' => 'invalid']));
        $rows = array_map(static fn ($i) => ['label' => 'Question ' . $i, 'value' => 'q-' . $i], range(1, 100));
        $settings = $type->normalize_settings(['rows' => array_merge([['value' => '__proto__'], ['value' => 'bad[key]'], ['value' => 'has space'], ['value' => 'a' . str_repeat('x', 64)]], $rows), 'options' => $rows, 'unknown' => true]);
        self::assertCount(50, $settings['rows']);
        self::assertCount(20, $settings['options']);
        self::assertSame('q-1', $settings['rows'][0]['value']);
        self::assertArrayNotHasKey('unknown', $settings);
    }

    public function testCollectsOnlyKnownRowsAndRejectsForgedOrMalformedAnswers(): void
    {
        $type = FieldRegistry::instance()->get('matrix');
        $element = $this->element();
        self::assertSame([], $type->collect([], $element));
        self::assertSame([], $type->collect(['survey' => ['__empty' => '']], $element));
        self::assertSame(['question-1' => '0'], $type->collect(['survey' => ['question-1' => '0', 'question-2' => '', 'rogue' => '5']], $element));
        foreach (['forged', ['nested'], new \stdClass()] as $invalid) {
            $value = $type->collect(['survey' => ['question-1' => $invalid]], $element);
            self::assertFalse($type->is_empty($value, $element));
            self::assertSame([['code' => 'option']], $type->validate($value, $element));
        }
        self::assertSame([['code' => 'option']], $type->validate(['unknown' => '1'], $element));
        foreach (['forged', '5', false, new \stdClass()] as $malformed) {
            self::assertSame([['code' => 'option']], $type->validate($type->collect(['survey' => $malformed], $element), $element));
        }
        self::assertSame(['question-1' => '5'], $type->collect(['survey' => ['question-1' => '<b>5</b>']], $element));
        self::assertSame([], $type->validate(['question-1' => '5'], $element));
        self::assertSame('Question 1: Strongly agree', $type->format_text(['question-1' => '5'], $element));
        self::assertSame('Question 1: retired', $type->format_text(['question-1' => 'retired'], $element));
        self::assertSame('', $type->format_text(null, $element));
    }

    public function testRequiredOptionalHiddenAndRepeatedMatricesKeepTheirDataContract(): void
    {
        $registry = FieldRegistry::instance();
        $element = $this->element([], true);
        $repeater = ['id' => 'rows', 'type' => 'group', 'key' => 'rows', 'settings' => ['repeatable' => true, 'minRows' => 0, 'maxRows' => 2], 'children' => [$element]];
        $tree = Tree::build([$repeater], $registry);
        $collector = new ValueCollector($tree, $registry);
        foreach ([[], ['question-1' => '5'], ['question-1' => '0'], ['question-1' => '1', 'question-2' => '5']] as $answers) {
            $values = $collector->collect(['rows' => [['survey' => $answers]]]);
            $visibility = (new Evaluator($tree))->evaluate($values);
            $errors = (new SubmissionValidator($tree, $registry))->validate($values, $visibility);
            self::assertSame(count($answers) < 2, isset($errors['rows[0].survey']));
            self::assertSame(['rows' => [['feedback' => $answers]]], (new DataBuilder($tree))->build($values, $visibility));
        }
        $element['required'] = false;
        $tree = Tree::build([$element], $registry);
        $visibility = (new Evaluator($tree))->evaluate([]);
        self::assertSame([], (new SubmissionValidator($tree, $registry))->validate(['survey' => []], $visibility));
        self::assertSame([], (new SubmissionValidator($tree, $registry))->validate(['survey' => ['question-1' => '4']], $visibility));
        $element['required'] = true;
        $element['logic'] = ['action' => 'hide', 'match' => 'all', 'rules' => [['field' => 'switch', 'op' => 'empty']]];
        $tree = Tree::build([['id' => 'switch', 'type' => 'input', 'key' => 'switch'], $element], $registry);
        $visibility = (new Evaluator($tree))->evaluate([]);
        self::assertSame([], (new SubmissionValidator($tree, $registry))->validate([], $visibility));
        self::assertArrayNotHasKey('feedback', (new DataBuilder($tree))->build([], $visibility));
    }

    public function testPersistsAnswersAndFormatsRowAndOptionLabels(): void
    {
        $service = \SWForms\WordPress\Forms\FormService::create_default();
        $repository = new \SWForms\WordPress\Forms\FormRepository();
        $id = $service->create('Matrix integration');
        try {
            $element = $this->element([], true);
            $saved = $service->save($id, 'Matrix integration', ['fields' => [$element], 'settings' => ['notifications' => []]]);
            self::assertSame([], $saved['errors']);
            self::assertSame('invalid', swf_submit($id, ['survey' => ['question-1' => '5']])->status);
            $result = swf_submit($id, ['survey' => ['question-1' => '5', 'question-2' => '1', 'injected' => '5']]);
            self::assertSame('success', $result->status);
            $entry = (new \SWForms\WordPress\Entries\EntryRepository())->find($result->entry_id);
            self::assertSame(['feedback' => ['question-1' => '5', 'question-2' => '1']], $entry['data']);
            $presenter = new \SWForms\WordPress\Entries\EntryPresenter(FieldRegistry::instance());
            $nodes = $presenter->present([$element], $entry['data']);
            self::assertSame("Question 1: Strongly agree\nQuestion 2: Strongly disagree", $nodes[0]['value']);
            self::assertStringContainsString('Question 2: Strongly disagree', $presenter::to_html($nodes));
            self::assertStringContainsString('Question 1: Strongly agree', $presenter::to_text($nodes));
        } finally {
            $repository->delete($id);
        }
    }

    public function testAccessibleMarkupEscapingPostbackAndRepeaterIdentifiers(): void
    {
        $registry = FieldRegistry::instance();
        $type = $registry->get('matrix');
        $element = $this->element([], true);
        $tree = Tree::build([$element], $registry);
        $context = new RenderContext(new Renderer($registry, $tree), 7, ['survey' => ['question-1' => '5']], ['survey' => 'Missing row'], null);
        $html = $type->render($element, $context);
        self::assertStringContainsString('<fieldset', $html);
        self::assertStringContainsString('Experience &lt;script&gt;', $html);
        self::assertStringContainsString('type="hidden" name="swf[survey][__empty]" value=""', $html);
        self::assertStringContainsString('name="swf[survey][question-1]"', $html);
        self::assertSame(10, substr_count($html, 'type="radio"'));
        self::assertSame(1, substr_count($html, ' checked'));
        self::assertStringContainsString('aria-labelledby="swf-7-survey-row-0 swf-7-survey-col-4"', $html);
        self::assertStringContainsString('aria-describedby="swf-7-survey-desc swf-7-survey-err"', $html);
        self::assertStringContainsString('aria-required="true"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('scope="row"', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertStringContainsString('name="swf[rows][1][survey][question-1]"', $type->render($element, $context->row('rows', '1', [])));
        self::assertSame('matrix', (new Renderer($registry, $tree))->public_tree([$element])[0]['kind']);
    }
}
