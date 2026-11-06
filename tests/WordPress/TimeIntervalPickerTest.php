<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Frontend\RenderContext;
use SWForms\WordPress\Frontend\Renderer;

final class TimeIntervalPickerTest extends TestCase
{
    public function testOnlyValidatedTimeIntervalsEnableConstrainedPickers(): void
    {
        $registry = FieldRegistry::instance();
        foreach (['time-interval', 'date-interval'] as $slug) {
            $type = $registry->get($slug);
            foreach ([true, false] as $validate) {
                $element = ['id' => 'times', 'type' => $slug, 'key' => 'times', 'label' => 'Times', 'settings' => $type->normalize_settings(['step' => 900, 'min' => '09:07', 'max' => '17:00', 'validate' => $validate])];
                $tree = Tree::build([$element], $registry);
                $context = new RenderContext(new Renderer($registry, $tree), 7, [], [], (new Evaluator($tree))->evaluate([]));
                $html = $type->render($element, $context);
                self::assertSame('time-interval' === $slug && $validate ? 2 : 0, substr_count($html, ' data-swf-time-picker'));
                self::assertStringContainsString('name="swf[times][from]"', $html);
                self::assertStringContainsString('name="swf[times][to]"', $html);
                if ('time-interval' === $slug && $validate) {
                    self::assertStringContainsString('step="900"', $html);
                    self::assertStringContainsString('min="09:07"', $html);
                    self::assertStringContainsString('type="time"', $html);
                }
            }
        }
    }
}
