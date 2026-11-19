<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Admin\AdminPage;

final class AdminFooterTest extends TestCase
{
    public function testFooterFollowsAdminAppInEnglishAndGerman(): void
    {
        $previousUser = get_current_user_id();
        $admin = get_users(['role' => 'administrator', 'number' => 1])[0];
        wp_set_current_user($admin->ID);
        try {
            $messages = [
                'en_US' => 'Open source by Stefan Warnat – free to use, free to change, better together.',
                'de_DE' => 'Open Source von Stefan Warnat – frei nutzbar, frei veränderbar, gemeinsam besser.',
            ];
            foreach ($messages as $locale => $expected) {
                $switchedLocale = switch_to_locale($locale);
                try {
                    unload_textdomain('sw-forms', true);
                    if ($locale === 'de_DE') {
                        self::assertTrue(load_textdomain('sw-forms', SWF_DIR . 'languages/sw-forms-de_DE.mo', $locale));
                    }
                    ob_start();
                    try {
                        (new AdminPage())->render();
                        $html = (string) ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                    self::assertStringContainsString('id="sw-forms-app"', $html);
                    self::assertStringEndsWith('<p class="swf-admin-footer">' . $expected . '</p>', $html);
                    self::assertStringNotContainsString('Powered by', $html);
                } finally {
                    unload_textdomain('sw-forms', true);
                    if ($switchedLocale) {
                        restore_previous_locale();
                    }
                }
            }
        } finally {
            wp_set_current_user($previousUser);
        }
    }
}
