<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\FileField;
use SWForms\WordPress\Frontend\RenderContext;
use SWForms\WordPress\Frontend\Renderer;

final class MediaFieldTest extends TestCase
{
    public function testRegistrationsAreFileFieldsWithNarrowAllowListsAndSingleFileLimits(): void
    {
        $registry = FieldRegistry::instance();
        foreach (['signature' => ['png'], 'audio' => ['webm', 'ogg', 'm4a', 'mp3', 'wav', 'mp4'], 'video' => ['webm', 'mp4']] as $slug => $extensions) {
            $type = $registry->get($slug);
            self::assertInstanceOf(FileField::class, $type);
            self::assertSame($slug, $type->type());
            self::assertNotEmpty($type->label());
            self::assertNotEmpty($type->icon());
            self::assertSame('media', $type->category());
            self::assertSame($extensions, array_keys($type->mimes([])));
            self::assertSame(1, $type->max_files(['settings' => ['multiple' => true, 'maxFiles' => 20]]));
            self::assertSame([], $type->validate_uploads([], ['id' => 'media']));
            $settings = $type->normalize_settings(['extensions' => 'php,txt,svg,png,webm', 'multiple' => true, 'maxSeconds' => 9999]);
            self::assertSame('signature' === $slug ? 'png' : 'webm', $settings['extensions']);
            if ('signature' !== $slug) {
                self::assertSame(600, $settings['maxSeconds']);
            }
            self::assertArrayNotHasKey('webm', (new FileField())->mimes(['settings' => ['extensions' => 'webm']]));
        }
    }

    public function testServerMarkupKeepsTheAccessibleNativeMultipartFallback(): void
    {
        $registry = FieldRegistry::instance();
        foreach (['signature', 'audio', 'video'] as $slug) {
            $type = $registry->get($slug);
            $element = ['id' => 'media', 'type' => $slug, 'key' => 'media', 'label' => 'Media', 'settings' => $type->normalize_settings([])];
            $tree = Tree::build([$element], $registry);
            $context = new RenderContext(new Renderer($registry, $tree), 9, [], [], (new Evaluator($tree))->evaluate([]));
            $html = $type->render($element, $context);
            self::assertStringContainsString('type="file"', $html);
            self::assertStringContainsString('name="swf[media][]"', $html);
            self::assertStringNotContainsString(' multiple', $html);
            self::assertStringContainsString('role="status"', $html);
            self::assertStringContainsString(' hidden', $html);
            self::assertStringContainsString('signature' === $slug ? 'data-swf-signature' : 'data-swf-recording="' . $slug . '"', $html);
        }
    }
}
