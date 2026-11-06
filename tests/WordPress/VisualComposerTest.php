<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class VisualComposerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOptionalEditorUsesExistingShortcodeAndOnlyPublicFormChoices(): void
    {
        if (function_exists('vc_map')) {
            self::markTestSkipped('The isolated stub requires WPBakery to be absent.');
        }
        require __DIR__ . '/../Support/vc-map-stub.php';
        $service = \SWForms\WordPress\Forms\FormService::create_default();
        $id = $service->create('VC public form');
        try {
            $service->save($id, 'VC public form', ['fields' => [], 'settings' => ['notifications' => []]]);
            (new \SWForms\WordPress\Editor\Embeds())->visual_composer();
            $map = $GLOBALS['swf_test_vc_map'];
            self::assertSame('sw_form', $map['base']);
            self::assertSame('id', $map['params'][0]['param_name']);
            self::assertContains((string) $id, array_values($map['params'][0]['value']));
            $service->repository()->update_status($id, 'inactive');
            (new \SWForms\WordPress\Editor\Embeds())->visual_composer();
            self::assertNotContains((string) $id, array_values($GLOBALS['swf_test_vc_map']['params'][0]['value']));
        } finally {
            $service->repository()->delete($id);
        }
    }
}
