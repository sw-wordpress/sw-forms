<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Frontend\FormView;
use SWForms\WordPress\Frontend\Postback;
use SWForms\WordPress\Spam\WpArmour;
use SWForms\WordPress\Submission\SpamGuard;
use SWForms\WordPress\Submission\SubmissionService;

final class WpArmourIntegrationTest extends TestCase
{
    public function testInstalledArmourProtectsRestAndPostbackAndSkipsTrustedAndPreview(): void
    {
        if (!WpArmour::available()) {
            self::markTestSkipped('Requires the optional WP Armour plugin.');
        }
        $user = get_current_user_id();
        $stats = get_option('wpa_stats');
        $words = get_option('wpae_blocked_words');
        $globals = [];
        foreach (['wpae_log_spammer_ip', 'wpae_log_spam_data', 'wpae_auto_block_ip'] as $key) {
            $globals[$key] = $GLOBALS[$key] ?? null;
            $GLOBALS[$key] = 'no';
        }
        wp_set_current_user(1);
        $service = FormService::create_default();
        $id = $service->create('WP Armour integration test');
        try {
            self::assertSame([], $service->save($id, 'WP Armour integration test', [
                'fields' => [['id' => 'name', 'key' => 'name', 'type' => 'input', 'required' => true]],
                'settings' => ['notifications' => [], 'spam' => ['minSeconds' => 0]],
            ])['errors']);
            self::assertStringContainsString('swf-form wpa_form', FormView::render($id));
            self::assertStringNotContainsString('swf-form wpa_form', FormView::render($id, ['preview' => true]));
            // Browser-injected fields travel through the shared metadata path for POST and REST.
            $body = ['swf' => ['name' => 'Allowed'], $GLOBALS['wpa_field_name'] => '12345', 'alt_s' => ''];
            $meta = Postback::request_meta($body);
            $meta['token'] = (new SpamGuard())->issue_token($id);
            $result = SubmissionService::create_default()->submit($id, $body['swf'], $meta);
            self::assertGreaterThan(0, $result->entry_id);

            rest_get_server();
            wp_set_current_user(0);
            $body['swf_token'] = (new SpamGuard())->issue_token($id);
            $request = new \WP_REST_Request('POST', '/swforms/v1/forms/' . $id . '/submit');
            $request->set_body_params($body);
            $response = rest_do_request($request);
            self::assertSame('success', $response->get_data()['status']);
            self::assertSame(2, (new \SWForms\WordPress\Entries\EntryRepository())->counts($id)['inbox']);

            foreach ([[], [$GLOBALS['wpa_field_name'] => '123', 'alt_s' => 'filled'], [$GLOBALS['wpa_field_name'] => [], 'alt_s' => '']] as $trap) {
                $meta = Postback::request_meta($trap + ['swf' => ['name' => 'Bot']]);
                $meta['token'] = (new SpamGuard())->issue_token($id);
                $result = SubmissionService::create_default()->submit($id, ['name' => 'Bot'], $meta);
                self::assertSame('success', $result->status);
                self::assertSame(0, $result->entry_id);
                $request = new \WP_REST_Request('POST', '/swforms/v1/forms/' . $id . '/submit');
                $request->set_body_params($trap + ['swf' => ['name' => 'Bot'], 'swf_token' => (new SpamGuard())->issue_token($id)]);
                self::assertSame('success', rest_do_request($request)->get_data()['status']);
                self::assertSame(2, (new \SWForms\WordPress\Entries\EntryRepository())->counts($id)['inbox']);
            }
            if (function_exists('wpae_check_is_spam')) {
                update_option('wpae_blocked_words', '["ArmourBlockedFixture"]');
                $meta = Postback::request_meta(array_replace($body, ['swf' => ['name' => 'ArmourBlockedFixture']]));
                $meta['token'] = (new SpamGuard())->issue_token($id);
                self::assertSame(0, SubmissionService::create_default()->submit($id, ['name' => 'ArmourBlockedFixture'], $meta)->entry_id);
            }
            self::assertSame(2, (new \SWForms\WordPress\Entries\EntryRepository())->counts($id)['inbox']);
            self::assertGreaterThan(0, swf_submit($id, ['name' => 'Trusted'])->entry_id);
            self::assertSame('preview', SubmissionService::create_default()->submit($id, ['name' => 'Preview'], ['preview' => true])->code);
        } finally {
            (new FormRepository())->delete($id);
            update_option('wpa_stats', $stats);
            if ($words === false) {
                delete_option('wpae_blocked_words');
            } else {
                update_option('wpae_blocked_words', $words);
            }
            foreach ($globals as $key => $value) {
                $GLOBALS[$key] = $value;
            }
            wp_set_current_user($user);
        }
    }
}
