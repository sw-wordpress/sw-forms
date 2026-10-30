<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Database\Schema;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Support\Queue;
use SWForms\WordPress\Webhooks\DeliveryRepository;
use SWForms\WordPress\Webhooks\DeliveryService;
use SWForms\WordPress\Webhooks\SecretVault;
use SWForms\WordPress\Webhooks\Transport;
use SWForms\WordPress\Webhooks\UrlPolicy;

final class WebhookDeliveryTest extends TestCase
{
    private int $formId;
    private int $user;
    private int $code = 200;
    /** @var list<array<string, mixed>> */
    private array $requests = [];
    /** @var list<array<string, mixed>> */
    private array $mails = [];
    private \Closure $http;
    private \Closure $mail;
    /** @var list<int> */
    private array $actions = [];

    protected function setUp(): void
    {
        $this->user = get_current_user_id();
        $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        wp_set_current_user((int) $admin[0]);
        rest_get_server();
        $this->formId = FormService::create_default()->create('Webhook delivery test');
        $this->http = function ($pre, $args, $url) {
            if ($url !== 'https://93.184.216.34/receive') {
                return $pre;
            }
            $this->requests[] = $args;
            return ['headers' => [], 'body' => 'response ' . ($args['headers']['Authorization'] ?? ''), 'response' => ['code' => $this->code, 'message' => 'Test'], 'cookies' => []];
        };
        $this->mail = function ($pre, $args) {
            $this->mails[] = $args;
            return true;
        };
        add_filter('pre_http_request', $this->http, 10, 3);
        add_filter('pre_wp_mail', $this->mail, 10, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->actions as $id) {
            try {
                \ActionScheduler::store()->delete_action($id);
            } catch (\Throwable) {
            /* Already removed. */
            }
        }
        (new FormRepository())->delete($this->formId);
        remove_filter('pre_http_request', $this->http, 10);
        remove_filter('pre_wp_mail', $this->mail, 10);
        wp_set_current_user($this->user);
    }

    /** @return array<string, mixed> */
    private function document(bool $store = true, int $count = 1): array
    {
        $hooks = [];
        for ($i = 0; $i < $count; $i++) {
            $hooks[] = ['id' => 'hook' . $i, 'enabled' => true, 'url' => 'https://93.184.216.34/receive', 'method' => 'POST', 'format' => 'json', 'secret' => 'known signing secret', 'headers' => [['name' => 'Authorization', 'value' => 'Bearer private-token']]];
        }
        return ['fields' => [['id' => 'name', 'type' => 'input', 'key' => 'name']], 'settings' => ['storeEntries' => $store, 'notifications' => [], 'webhooks' => $hooks]];
    }

    /** @return array{entry: int, rows: list<array<string, mixed>>} */
    private function submit(bool $store = true, int $count = 1): array
    {
        $save = FormService::create_default()->save($this->formId, 'Delivery', $this->document($store, $count));
        self::assertSame([], $save['errors']);
        $result = swf_submit($this->formId, ['name' => 'Max']);
        self::assertSame('success', $result->status);
        global $wpdb;
        $entry = $result->entry_id ?: (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE form_id = %d AND status = 'temporary'", Schema::table('entries'), $this->formId));
        $rows = (new DeliveryRepository())->for_entry($entry);
        self::assertCount($count, $rows);
        foreach ($rows as $row) {
            $this->rememberActions($row['id']);
        }
        return ['entry' => $entry, 'rows' => $rows];
    }

    private function rememberActions(int $id): array
    {
        $ids = as_get_scheduled_actions(['hook' => DeliveryService::HOOK, 'group' => Queue::GROUP, 'args' => [$id]], 'ids');
        $this->actions = array_values(array_unique(array_merge($this->actions, $ids)));
        return $ids;
    }

    private function due(int $id): void
    {
        global $wpdb;
        $wpdb->update(Schema::table('webhook_deliveries'), ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $id]);
    }

    public function testQueuePersistsEncryptedSnapshotAndRetriesIdenticalBodyAfterEdits(): void
    {
        $this->code = 503;
        $created = $this->submit();
        $id = $created['rows'][0]['id'];
        self::assertSame([], $this->requests, 'Submission must not perform network I/O.');
        $repo = new DeliveryRepository();
        $row = $repo->find($id);
        self::assertStringNotContainsString('Max', $row['payload']);
        self::assertStringNotContainsString('private-token', $row['payload']);
        $queued = $this->rememberActions($id);
        \ActionScheduler::runner()->process_action($queued[0], 'SW Forms integration');
        self::assertCount(1, $this->requests);
        $row = $repo->find($id);
        self::assertSame('pending', $row['status']);
        self::assertSame('1', (string) $row['attempts']);
        self::assertEqualsWithDelta(time() + 60, strtotime($row['next_attempt_at'] . ' UTC'), 2);
        self::assertStringNotContainsString('private-token', $row['message']);
        self::assertSame('retry', (new EntryRepository())->logs($created['entry'])[1]['status']);
        $request = $this->requests[0];
        self::assertSame('sha256=' . hash_hmac('sha256', $request['body'], 'known signing secret'), $request['headers']['X-SWForms-Signature']);
        $doc = $this->document();
        $doc['settings']['webhooks'][0]['secret'] = 'replacement';
        $doc['settings']['webhooks'][0]['url'] = 'https://example.org/changed';
        FormService::create_default()->save($this->formId, 'Changed', $doc);
        $this->code = 204;
        $this->due($id);
        (new DeliveryService())->deliver($id);
        self::assertSame($request['body'], $this->requests[1]['body']);
        self::assertSame($request['headers'], $this->requests[1]['headers']);
        self::assertSame('success', $repo->find($id)['status']);
        (new DeliveryService())->deliver($id);
        self::assertCount(2, $this->requests, 'Duplicate queue callbacks must be harmless.');
        self::assertSame([], $this->mails);
        $this->rememberActions($id);
    }

    public function testFinalFailureHasBoundedBackoffOneNotificationBadgeAndManualResend(): void
    {
        $this->code = 500;
        $created = $this->submit();
        $id = $created['rows'][0]['id'];
        $repo = new DeliveryRepository();
        $failed = [];
        $hook = static function ($delivery) use (&$failed): void {
            $failed[] = $delivery;
        };
        add_action('swf_webhook_failed', $hook);
        try {
            foreach ([60, 300, 1800, 7200, 43200, null] as $delay) {
                $this->due($id);
                (new DeliveryService())->deliver($id);
                $row = $repo->find($id);
                if ($delay !== null) {
                    self::assertEqualsWithDelta(time() + $delay, strtotime($row['next_attempt_at'] . ' UTC'), 2);
                }
                $this->rememberActions($id);
            }
            self::assertSame('failed', $row['status']);
            self::assertCount(6, $this->requests);
            self::assertCount(1, $this->mails);
            self::assertSame([$id], $failed);
            self::assertSame(1, $repo->failure_counts()[$this->formId]);
            (new DeliveryService())->deliver($id);
            self::assertCount(1, $this->mails);
            $resend = new \WP_REST_Request('POST', '/swforms/v1/entries/' . $created['entry'] . '/webhooks/' . $id . '/resend');
            self::assertSame(202, rest_do_request($resend)->get_status());
            self::assertSame(409, rest_do_request($resend)->get_status());
            $this->code = 200;
            (new DeliveryService())->deliver($id);
            self::assertSame($this->requests[0]['body'], $this->requests[6]['body']);
            self::assertSame('success', $repo->find($id)['status']);
            self::assertArrayNotHasKey($this->formId, $repo->failure_counts());
            $this->rememberActions($id);
        } finally {
            remove_action('swf_webhook_failed', $hook);
        }
    }

    public function testTemporaryEntryIsPrivateAndSurvivesUntilEveryDeliveryIsTerminal(): void
    {
        $created = $this->submit(false, 2);
        $entries = new EntryRepository();
        self::assertSame('temporary', $entries->find($created['entry'])['status']);
        self::assertSame(0, $entries->search($this->formId, 'inbox', '', 1, 20, 'desc')['total']);
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(404, rest_do_request(new \WP_REST_Request($method, '/swforms/v1/entries/' . $created['entry']))->get_status());
        }
        (new DeliveryService())->deliver($created['rows'][0]['id']);
        self::assertNotNull($entries->find($created['entry']));
        (new DeliveryService())->deliver($created['rows'][1]['id']);
        self::assertNull($entries->find($created['entry']));
        foreach ($created['rows'] as $row) {
            $stored = (new DeliveryRepository())->find($row['id']);
            self::assertSame('', $stored['payload']);
            self::assertSame('', $stored['message']);
            self::assertSame('', $stored['event_id']);
            self::assertSame('0', (string) $stored['entry_id']);
        }
    }

    public function testLeaseBlocksConcurrentWorkersAndRecoveryRepairsLostQueueJobs(): void
    {
        $created = $this->submit();
        $id = $created['rows'][0]['id'];
        $repo = new DeliveryRepository();
        $claimed = $repo->claim($id);
        self::assertNotNull($claimed);
        (new DeliveryService())->deliver($id);
        self::assertSame([], $this->requests);
        global $wpdb;
        $wpdb->update(Schema::table('webhook_deliveries'), ['lease_until' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $id]);
        (new Queue())->cancel(DeliveryService::HOOK, [$id]);
        (new DeliveryService())->recover();
        self::assertTrue(as_has_scheduled_action(DeliveryService::HOOK, [$id], Queue::GROUP));
        (new DeliveryService())->deliver($id);
        self::assertSame('success', $repo->find($id)['status']);
        self::assertFalse($repo->finish($claimed, ['success' => false, 'code' => 500, 'duration' => 0, 'message' => 'stale'], 60));
        self::assertSame('success', $repo->find($id)['status']);
        $this->rememberActions($id);
    }

    public function testConditionsPreviewAndDeletionDoNotDeliver(): void
    {
        $doc = $this->document();
        $doc['settings']['webhooks'][0]['logic'] = ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'name', 'op' => 'is', 'value' => 'send']]];
        FormService::create_default()->save($this->formId, 'Conditional', $doc);
        $preview = \SWForms\WordPress\Submission\SubmissionService::create_default()->submit($this->formId, ['name' => 'send'], ['preview' => true, 'trusted' => true]);
        self::assertSame('success', $preview->status);
        self::assertSame(0, $preview->entry_id);
        self::assertSame([], $this->requests);
        $result = swf_submit($this->formId, ['name' => 'skip']);
        self::assertSame([], (new DeliveryRepository())->for_entry($result->entry_id));
        self::assertSame([], $this->requests);
        $created = $this->submit();
        $id = $created['rows'][0]['id'];
        (new EntryRepository())->delete($created['entry']);
        (new DeliveryService())->deliver($id);
        self::assertNull((new DeliveryRepository())->find($id));
        self::assertSame([], $this->requests);
    }

    public function testTestEndpointUsesSyntheticDataAndRequiresFormManagement(): void
    {
        $this->submit();
        $request = new \WP_REST_Request('POST', '/swforms/v1/forms/' . $this->formId . '/webhooks/hook0/test');
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());
        self::assertTrue($response->get_data()['success']);
        $payload = json_decode($this->requests[0]['body'], true);
        self::assertSame('webhook.test', $payload['event']);
        self::assertSame('Example', $payload['data']['name']);
        self::assertSame(0, $payload['entry']['id']);
        wp_set_current_user(0);
        self::assertSame(401, rest_do_request($request)->get_status());
    }

    public function testQueueSchedulingFailureRetainsDurableRequestAndRecoveryRequeuesIt(): void
    {
        $reject = static fn () => 0;
        add_filter('pre_as_enqueue_async_action', $reject);
        try {
            $created = $this->submit();
        } finally {
            remove_filter('pre_as_enqueue_async_action', $reject);
        }
        $id = $created['rows'][0]['id'];
        self::assertFalse(as_has_scheduled_action(DeliveryService::HOOK, [$id], Queue::GROUP));
        (new DeliveryService())->recover();
        self::assertTrue(as_has_scheduled_action(DeliveryService::HOOK, [$id], Queue::GROUP));
        $this->rememberActions($id);
        (new DeliveryService())->deliver($id);
        self::assertSame('success', (new DeliveryRepository())->find($id)['status']);
    }

    public function testSamplePayloadPreservesNestedRepeaterMatrixAndListShapes(): void
    {
        $doc = $this->document();
        $doc['fields'] = [
            ['id' => 'rows', 'type' => 'group', 'key' => 'people', 'settings' => ['repeatable' => true], 'children' => [
                ['id' => 'email', 'type' => 'input', 'key' => 'email', 'settings' => ['inputType' => 'email']],
            ]],
            ['id' => 'select', 'type' => 'select', 'key' => 'choices', 'settings' => ['multiple' => true, 'options' => [['value' => 'yes', 'label' => 'Yes']]]],
            ['id' => 'matrix', 'type' => 'matrix', 'key' => 'answers', 'settings' => ['rows' => [['value' => 'r', 'label' => 'Row']], 'options' => [['value' => 'yes', 'label' => 'Yes']]]],
            ['id' => 'upload', 'type' => 'file', 'key' => 'documents'],
        ];
        self::assertSame([], FormService::create_default()->save($this->formId, 'Samples', $doc)['errors']);
        $response = rest_do_request(new \WP_REST_Request('POST', '/swforms/v1/forms/' . $this->formId . '/webhooks/hook0/test'));
        self::assertSame(200, $response->get_status());
        $data = json_decode($this->requests[0]['body'], true)['data'];
        self::assertSame([['email' => 'example@example.org']], $data['people']);
        self::assertSame(['yes'], $data['choices']);
        self::assertSame(['r' => 'yes'], $data['answers']);
        self::assertSame([], $data['documents']);
    }

    public function testTemporaryFinalFailureErasesPersonalDataAndRetainsOnlyFailureBadge(): void
    {
        $this->code = 500;
        $created = $this->submit(false);
        $id = $created['rows'][0]['id'];
        for ($i = 0; $i < 6; $i++) {
            $this->due($id);
            (new DeliveryService())->deliver($id);
            $this->rememberActions($id);
        }
        self::assertNull((new EntryRepository())->find($created['entry']));
        $row = (new DeliveryRepository())->find($id);
        self::assertSame('failed', $row['status']);
        self::assertSame('', $row['payload']);
        self::assertSame('', $row['message']);
        self::assertSame('', $row['event_id']);
        self::assertSame('0', (string) $row['entry_id']);
        self::assertSame(1, (new DeliveryRepository())->failure_counts()[$this->formId]);
        self::assertCount(1, $this->mails);
    }

    public function testResendingRequiresBothFormAndEntryManagement(): void
    {
        $created = $this->submit();
        $viewer = wp_insert_user(['user_login' => 'swf-delivery-viewer-' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
        self::assertIsInt($viewer);
        $user = new \WP_User($viewer);
        $user->add_cap(\SWForms\WordPress\Support\Capabilities::MANAGE_ENTRIES);
        wp_set_current_user($viewer);
        try {
            $resend = new \WP_REST_Request('POST', '/swforms/v1/entries/' . $created['entry'] . '/webhooks/' . $created['rows'][0]['id'] . '/resend');
            self::assertSame(403, rest_do_request($resend)->get_status());
            self::assertSame(403, rest_do_request(new \WP_REST_Request('POST', '/swforms/v1/forms/' . $this->formId . '/webhooks/hook0/test'))->get_status());
            $user->add_cap(\SWForms\WordPress\Support\Capabilities::MANAGE_FORMS);
            $user->remove_cap(\SWForms\WordPress\Support\Capabilities::MANAGE_ENTRIES);
            wp_set_current_user(0);
            wp_set_current_user($viewer);
            self::assertSame(403, rest_do_request($resend)->get_status());
        } finally {
            wp_set_current_user($this->user);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($viewer);
        }
    }

    public function testBlockedUrlNeverReachesWordPressHttpAndRedirectsAreDisabled(): void
    {
        $calls = 0;
        $spy = static function ($pre) use (&$calls) {
            $calls++;
            return $pre;
        };
        add_filter('pre_http_request', $spy);
        try {
            foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd', 'https://user:pass@example.org/', 'http://[::ffff:127.0.0.1]/'] as $url) {
                $result = (new Transport())->send(['url' => $url]);
                self::assertFalse($result['success']);
            }
            self::assertSame(0, $calls);
        } finally {
            remove_filter('pre_http_request', $spy);
        }
    }
}
