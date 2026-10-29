<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\ImageField;
use SWForms\WordPress\Frontend\RenderContext;
use SWForms\WordPress\Frontend\Renderer;

final class ImageFieldTest extends TestCase
{
    public function testRegistrationDefaultsAndImageAllowlistCannotBeWidened(): void
    {
        $type = FieldRegistry::instance()->get('image');
        self::assertInstanceOf(ImageField::class, $type);
        self::assertSame('image', $type->type());
        self::assertNotEmpty($type->label());
        self::assertNotEmpty($type->icon());
        self::assertSame('media', $type->category());
        self::assertTrue($type->default_settings()['preview']);
        self::assertSame('', $type->default_settings()['capture']);
        $settings = $type->normalize_settings(['extensions' => '.PNG,svg,pdf,txt,jpg,png', 'capture' => 'unsupported']);
        self::assertSame('png,jpg', $settings['extensions']);
        self::assertSame('', $settings['capture']);
        self::assertSame('', $type->normalize_settings(['extensions' => 'pdf,svg'])['extensions']);
        self::assertArrayNotHasKey('pdf', $type->mimes(['settings' => ['extensions' => 'pdf,png']]));
        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], array_keys($type->mimes([])));
        self::assertSame([], $type->validate_uploads([], ['id' => 'photo']));
    }

    public function testImageControlsRenderCaptureHintsAndOptionalProgressivePreviews(): void
    {
        $registry = FieldRegistry::instance();
        $type = $registry->get('image');
        $element = ['id' => 'photo', 'type' => 'image', 'key' => 'photo', 'label' => 'Photo', 'settings' => $type->normalize_settings(['capture' => 'environment'])];
        $tree = Tree::build([$element], $registry);
        $renderer = new Renderer($registry, $tree);
        $context = new RenderContext($renderer, 7, ['photo' => []], [], (new Evaluator($tree))->evaluate(['photo' => []]));
        $html = $type->render($element, $context);
        self::assertStringContainsString('capture="environment"', $html);
        self::assertStringContainsString('data-swf-image', $html);
        self::assertStringContainsString('aria-live="polite" hidden', $html);
        self::assertStringContainsString('name="swf[photo][]"', $html);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/gif,image/webp"', $html);
        self::assertStringNotContainsString('value="', $html);
        $element['settings']['capture'] = 'user';
        self::assertStringContainsString('capture="user"', $type->render($element, $context));
        $element['settings']['capture'] = 'bad';
        $element['settings']['preview'] = false;
        $html = $type->render($element, $context);
        self::assertStringNotContainsString(' capture=', $html);
        self::assertStringNotContainsString('data-swf-image', $html);
        self::assertStringNotContainsString('swf-image-previews', $html);
        $element['settings']['preview'] = true;
        $element['settings']['crop'] = true;
        self::assertStringContainsString('data-swf-crop ', $type->render($element, $context));
        $element['settings']['extensions'] = 'jpg';
        self::assertStringNotContainsString('data-swf-crop ', $type->render($element, $context));
    }
}
