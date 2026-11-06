<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Spam\WpArmour;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WpArmourTest extends TestCase
{
    private function loadArmour(bool $extended = false): void
    {
        require __DIR__ . '/../Support/wp-armour-stub.php';
        $GLOBALS['wpa_field_name'] = 'random_trap';
        if ($extended) {
            require __DIR__ . '/../Support/wp-armour-extended-stub.php';
        }
    }

    public function testInactivePluginDoesNotBlockSubmissions(): void
    {
        self::assertFalse(WpArmour::available());
        self::assertFalse(WpArmour::is_spam([]));
    }

    public function testFreeVersionAcceptsInjectedFieldsAndRejectsBots(): void
    {
        $this->loadArmour();
        self::assertTrue(WpArmour::available());
        self::assertFalse(WpArmour::is_spam(['random_trap' => '123', 'alt_s' => '']));
        foreach ([[], ['random_trap' => '123'], ['random_trap' => '123', 'alt_s' => 'bot'], ['random_trap' => [], 'alt_s' => ''], ['random_trap' => '123', 'alt_s' => []]] as $data) {
            self::assertTrue(WpArmour::is_spam($data));
        }
        self::assertCount(5, $GLOBALS['armour_events']);
        self::assertSame(['wpa_handle_spammers', 'sw_forms', []], $GLOBALS['armour_events'][0]);
    }

    public function testExtendedChecksNestedFormValuesAndReceivesSpamEvents(): void
    {
        $this->loadArmour(true);
        $post = ['random_trap' => '123', 'alt_s' => '', 'swf' => ['people' => [['name' => 'blocked']]], 'swf_token' => 'secret', 'unrelated' => 'private'];
        $data = WpArmour::request_data($post);
        self::assertArrayNotHasKey('swf_token', $data);
        self::assertArrayNotHasKey('unrelated', $data);
        self::assertTrue(WpArmour::is_spam($data));
        self::assertSame($data, $GLOBALS['armour_events'][0][2]);
        $data['swf']['people'][0]['name'] = 'Allowed';
        self::assertFalse(WpArmour::is_spam($data));
    }

    public function testExtractionPreservesMissingFieldsAndConfiguredFieldName(): void
    {
        $this->loadArmour();
        self::assertSame(['swf' => []], WpArmour::request_data([]));
        self::assertSame(['swf' => [], 'random_trap' => '5', 'alt_s' => ''], WpArmour::request_data(['random_trap' => '5', 'alt_s' => '', 'old_trap' => '6']));
    }
}
