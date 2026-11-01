<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\DocumentStats;
use SWForms\Core\Form\TypeCatalog;

final class DocumentStatsTest extends TestCase
{
    public function testCountsDataFieldsNestingAndSteps(): void
    {
        $document = [
            'fields' => [
                ['id' => 'intro', 'type' => 'html', 'settings' => ['content' => 'x']],
                ['id' => 'email', 'type' => 'input', 'key' => 'email', 'required' => true],
                [
                    'id' => 'row',
                    'type' => 'columns',
                    'columns' => [
                        ['id' => 'row_1', 'width' => 6, 'children' => [['id' => 'first', 'type' => 'input', 'key' => 'first']]],
                        ['id' => 'row_2', 'width' => 6, 'children' => [
                            ['id' => 'group', 'type' => 'group', 'key' => 'group', 'children' => [
                                ['id' => 'nested', 'type' => 'textarea', 'key' => 'nested'],
                                ['id' => 'deeper', 'type' => 'input', 'key' => 'deeper', 'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'nested', 'op' => 'notempty']]]],
                            ]],
                        ]],
                    ],
                ],
                ['id' => 'break', 'type' => 'page-break', 'label' => 'Next'],
                ['id' => 'rating', 'type' => 'rating', 'key' => 'rating', 'logic' => ['action' => 'require', 'match' => 'any', 'rules' => [['field' => 'email', 'op' => 'notempty']]]],
            ],
            'settings' => ['steps' => ['enabled' => true]],
        ];

        self::assertSame(
            ['fields' => 6, 'steps' => 2, 'conditions' => 2, 'conditionalRequired' => 1],
            DocumentStats::measure($document)
        );
    }

    public function testDocumentsWithoutStepsCountAsASingleStep(): void
    {
        // Without an explicit `settings.steps` the breaks still split the form (normalizer rule).
        $withBreaks = ['fields' => [['id' => 'a', 'type' => 'input', 'key' => 'a'], ['id' => 'b1', 'type' => 'page-break'], ['id' => 'c', 'type' => 'input', 'key' => 'c']]];
        self::assertSame(['fields' => 2, 'steps' => 2, 'conditions' => 0, 'conditionalRequired' => 0], DocumentStats::measure($withBreaks));
        // Explicitly switched off multi-step stays one visible page, even with a break.
        $document = ['fields' => [['id' => 'a', 'type' => 'input', 'key' => 'a'], ['id' => 'b', 'type' => 'page-break']], 'settings' => ['steps' => ['enabled' => false]]];
        self::assertSame(1, DocumentStats::measure($document)['steps']);
    }

    public function testMalformedDocumentsAndUnknownTypesNeverBreakTheCounts(): void
    {
        self::assertSame(['fields' => 0, 'steps' => 1, 'conditions' => 0, 'conditionalRequired' => 0], DocumentStats::measure([]));
        self::assertSame(0, DocumentStats::measure(['fields' => 'not-a-list'])['fields']);
        self::assertSame(0, DocumentStats::measure(['fields' => ['scalar', ['id' => 'x']]])['fields']);
        self::assertSame(0, DocumentStats::measure(['fields' => [['id' => 'x', 'type' => 'mystery']]])['fields']);
        // Broken containers are skipped instead of throwing.
        self::assertSame(0, DocumentStats::measure(['fields' => [['id' => 'c', 'type' => 'columns', 'columns' => ['nope']]]])['fields']);
        // A group always produces its own object, even when its children list is unusable.
        self::assertSame(1, DocumentStats::measure(['fields' => [['id' => 'g', 'type' => 'group', 'key' => 'g', 'children' => 'nope']]])['fields']);
    }

    public function testACustomTypeCatalogDecidesWhatCountsAsData(): void
    {
        $catalog = new class implements TypeCatalog {
            public function has(string $type): bool
            {
                return $type === 'badge';
            }

            public function isData(string $type): bool
            {
                return $type === 'badge';
            }

            public function container(string $type): string
            {
                return TypeCatalog::CONTAINER_NONE;
            }
        };

        $document = ['fields' => [['id' => 'b', 'type' => 'badge', 'key' => 'b'], ['id' => 'input', 'type' => 'input', 'key' => 'input']]];
        self::assertSame(1, DocumentStats::measure($document, $catalog)['fields']);
        // The built-in catalog counts the input, the add-on type stays unknown there.
        self::assertSame(1, DocumentStats::measure($document)['fields']);
    }
}
