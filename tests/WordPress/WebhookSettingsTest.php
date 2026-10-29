<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Submission\Submission;
use SWForms\WordPress\Webhooks\RequestBuilder;
use SWForms\WordPress\Webhooks\SecretVault;
use SWForms\WordPress\Webhooks\WebhookSettings;

final class WebhookSettingsTest extends TestCase
{
    /** @var list<int> */
    private array $forms = [];
    private int $previousUser;

    protected function setUp(): void
    {
        $this->previousUser = get_current_user_id();
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        wp_set_current_user((int) $admins[0]);
        rest_get_server();
    }

    protected function tearDown(): void
    {
        foreach ($this->forms as $id) {
            (new FormRepository())->delete($id);
        }
        wp_set_current_user($this->previousUser);
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/swforms/v1/' . $path);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
        return rest_do_request($request);
    }

    private function create(): int
    {
        $id = FormService::create_default()->create('Webhook settings test');
        $this->forms[] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private function document(string $secret = ''): array
    {
        return ['fields' => [['id' => 'name', 'type' => 'input', 'key' => 'name']], 'settings' => ['notifications' => [], 'webhooks' => [[
            'id' => 'hook', 'enabled' => true, 'url' => 'https://example.org/receive', 'method' => 'PUT', 'format' => 'json',
            'headers' => [['name' => 'Authorization', 'value' => 'Bearer example']], 'mapping' => [['field' => 'name', 'target' => 'receiver']], 'secret' => $secret,
        ]]]];
    }

    /** @return array<string, mixed> */
    private function storedHook(int $id): array
    {
        return (new FormRepository())->published($id)['settings']['webhooks'][0];
    }

    public function testRestRoundTripPreservesEncryptedSecretAndStableVersion(): void
    {
        $id = $this->create();
        $saved = $this->request('PUT', 'forms/' . $id, ['title' => 'Webhook', 'document' => $this->document('Known secret with ä and spaces')]);
        self::assertSame(200, $saved->get_status());
        $public = $saved->get_data()['document'];
        self::assertTrue($public['settings']['webhooks'][0]['hasSecret']);
        self::assertArrayNotHasKey('secret', $public['settings']['webhooks'][0]);
        self::assertArrayNotHasKey('secretEncrypted', $public['settings']['webhooks'][0]);
        $ciphertext = $this->storedHook($id)['secretEncrypted'];
        self::assertSame('Known secret with ä and spaces', SecretVault::decrypt($ciphertext));
        self::assertStringNotContainsString('Known secret', (string) wp_json_encode((new FormRepository())->published($id)));
        self::assertSame(200, $this->request('PUT', 'forms/' . $id . '/draft', ['document' => $public])->get_status());
        $savedAgain = $this->request('PUT', 'forms/' . $id, ['title' => 'Webhook', 'document' => $public]);
        self::assertSame(1, $savedAgain->get_data()['currentVersion']);
        self::assertSame($ciphertext, $this->storedHook($id)['secretEncrypted']);
        // An injected ciphertext cannot replace the stored secret.
        $public['settings']['webhooks'][0]['secretEncrypted'] = SecretVault::encrypt('attacker');
        $this->request('PUT', 'forms/' . $id, ['title' => 'Webhook', 'document' => $public]);
        self::assertSame($ciphertext, $this->storedHook($id)['secretEncrypted']);
    }

    public function testDraftRotationAndDiscardRestorePublishedSecret(): void
    {
        $id = $this->create();
        $this->request('PUT', 'forms/' . $id, ['title' => 'Webhook', 'document' => $this->document('original')]);
        $this->request('PUT', 'forms/' . $id . '/draft', ['document' => $this->document('rotated')]);
        $repo = new FormRepository();
        self::assertSame('rotated', SecretVault::decrypt($repo->find($id)['draft']['settings']['webhooks'][0]['secretEncrypted']));
        self::assertSame('original', SecretVault::decrypt($this->storedHook($id)['secretEncrypted']));
        $this->request('DELETE', 'forms/' . $id . '/draft');
        self::assertNull($repo->find($id)['draft']);
        self::assertSame('original', SecretVault::decrypt($this->storedHook($id)['secretEncrypted']));
        self::assertSame(1, $repo->find($id)['current_version']);
    }

    public function testDuplicateGetsIndependentSecretAndInvalidDraftIsEncrypted(): void
    {
        $id = $this->create();
        FormService::create_default()->save($id, 'Webhook', $this->document('original'));
        $copy = FormService::create_default()->duplicate($id);
        $this->forms[] = $copy;
        self::assertNotSame('original', SecretVault::decrypt($this->storedHook($copy)['secretEncrypted']));
        $invalid = $this->document('invalid draft secret');
        $invalid['settings']['webhooks'][0]['url'] = 'file:///etc/passwd';
        $response = $this->request('PUT', 'forms/' . $id, ['title' => 'Webhook', 'document' => $invalid]);
        self::assertSame(422, $response->get_status());
        self::assertStringNotContainsString('invalid draft secret', (string) wp_json_encode((new FormRepository())->find($id)));
        $read = $this->request('GET', 'forms/' . $id)->get_data()['document'];
        self::assertArrayNotHasKey('secretEncrypted', $read['settings']['webhooks'][0]);
    }

    public function testValidationRejectsInjectionAmbiguousMappingAndRepeaterScope(): void
    {
        $service = FormService::create_default();
        $base = $this->document();
        $bad = [
            ['method' => 'DELETE'], ['format' => 'xml'], ['url' => 'https://user:pass@example.org/'],
            ['headers' => [['name' => 'X-Test', 'value' => "ok\r\nInjected: bad"]]],
            ['headers' => [['name' => 'x-swforms-signature', 'value' => 'forged']]],
            ['headers' => [['name' => 'Host', 'value' => 'internal']]],
            ['mapping' => [['field' => 'unknown', 'target' => 'name']]],
            ['mapping' => [['field' => 'name', 'target' => 'same'], ['field' => 'name', 'target' => 'same']]],
            ['logic' => ['action' => 'show', 'rules' => [['field' => 'missing', 'op' => 'is', 'value' => 'x']]]],
            ['logic' => ['action' => 'show', 'rules' => [['field' => 'name', 'op' => 'unknown', 'value' => 'x']]]],
        ];
        foreach ($bad as $patch) {
            $doc = $base;
            $doc['settings']['webhooks'][0] = array_replace($doc['settings']['webhooks'][0], $patch);
            self::assertSame('webhook:hook', $service->validate($service->normalize($doc))[0]['path'], (string) wp_json_encode($patch));
        }
        $doc = $base;
        $doc['settings']['webhooks'][] = $doc['settings']['webhooks'][0];
        self::assertNotEmpty($service->validate($service->normalize($doc)));
        $doc = $base;
        $doc['fields'] = [['id' => 'rows', 'type' => 'group', 'key' => 'rows', 'settings' => ['repeatable' => true], 'children' => $base['fields']]];
        self::assertNotEmpty($service->validate($service->normalize($doc)));
    }

    public function testEncryptedEnvelopeRejectsTamperingAndRedactionIsExportSafe(): void
    {
        $encrypted = SecretVault::encrypt('example');
        $protected = WebhookSettings::protect($this->document('example'));
        self::assertStringNotContainsString('secretEncrypted', (string) wp_json_encode(WebhookSettings::redact($protected)));
        $bytes = base64_decode(substr($encrypted, 3), true);
        $bytes[15] = chr(ord($bytes[15]) ^ 1);
        $this->expectException(\RuntimeException::class);
        SecretVault::decrypt('v1:' . base64_encode($bytes));
    }

    public function testEntryViewerCannotReadWebhookConfigurationOrAuthenticationHeaders(): void
    {
        $id = $this->create();
        FormService::create_default()->save($id, 'Webhook', $this->document('signing secret'));
        $admin = get_current_user_id();
        $viewer = wp_insert_user(['user_login' => 'swf-webhook-viewer-' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
        self::assertIsInt($viewer);
        (new \WP_User($viewer))->add_cap(\SWForms\WordPress\Support\Capabilities::VIEW_ENTRIES);
        wp_set_current_user($viewer);
        try {
            $response = $this->request('GET', 'forms/' . $id);
            self::assertSame(200, $response->get_status());
            self::assertArrayNotHasKey('webhooks', $response->get_data()['document']['settings']);
            self::assertStringNotContainsString('Bearer example', (string) wp_json_encode($response->get_data()));
            wp_set_current_user(0);
            self::assertSame(401, $this->request('GET', 'forms/' . $id)->get_status());
        } finally {
            wp_set_current_user($admin);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($viewer);
        }
    }

    public function testRequestSignsExactJsonAndFormBodyAndPayloadFilterRunsBeforeSigning(): void
    {
        $id = $this->create();
        FormService::create_default()->save($id, 'Webhook', $this->document('signing secret'));
        $doc = (new FormRepository())->published($id);
        $tree = Tree::build($doc['fields'], FieldRegistry::instance());
        $submission = new Submission(['id' => $id, 'title' => 'Webhook'], 1, $doc, $tree, ['name' => 'Mäx & Co'], (new Evaluator($tree))->evaluate(['name' => 'Mäx & Co']), ['name' => 'Mäx & Co'], 0, new EntryRepository());
        $filter = static function ($payload, $webhook) {
            self::assertArrayNotHasKey('secretEncrypted', $webhook);
            $payload['custom'] = 'filtered';
            return $payload;
        };
        add_filter('swf_webhook_payload', $filter, 10, 2);
        try {
            $builder = new RequestBuilder();
            $hook = $this->storedHook($id);
            $payload = $builder->payload($submission, $hook, 'event-1');
            self::assertSame(['receiver' => 'Mäx & Co'], (array) $payload['data']);
            foreach (['json', 'form'] as $format) {
                $hook['format'] = $format;
                $request = $builder->build($payload, $hook);
                self::assertSame('PUT', $request['method']);
                self::assertSame('sha256=' . hash_hmac('sha256', $request['body'], 'signing secret'), $request['headers']['X-SWForms-Signature']);
                self::assertSame('Bearer example', $request['headers']['Authorization']);
                if ($format === 'json') {
                    self::assertSame('filtered', json_decode($request['body'], true)['custom']);
                } else {
                    parse_str($request['body'], $parsed);
                    self::assertSame('Mäx & Co', $parsed['data']['receiver']);
                }
            }
        } finally {
            remove_filter('swf_webhook_payload', $filter, 10);
        }
    }
}
