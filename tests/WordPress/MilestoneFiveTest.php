<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\PortableDocument;
use SWForms\WordPress\Frontend\FormView;
use SWForms\WordPress\Frontend\Postback;
use SWForms\WordPress\Submission\SpamGuard;
use SWForms\WordPress\Submission\SubmissionService;
use SWForms\WordPress\Uploads\UploadStorage;

final class MilestoneFiveTest extends TestCase
{
    private int $user;
    private array $forms = [];
    protected function setUp(): void
    {
        $this->user = get_current_user_id();
        wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
        rest_get_server();
    }
    protected function tearDown(): void
    {
        foreach ($this->forms as $id) {
            (new FormRepository())->delete($id);
        }
        wp_set_current_user($this->user);
    }
    private function api(string $method, string $path, array $body = []): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/swforms/v1/' . $path);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
        return rest_do_request($request);
    }
    private function form(array $document): int
    {
        $service = FormService::create_default();
        $id = $service->create('M5 test');
        $this->forms[] = $id;
        $document['settings'] = ($document['settings'] ?? []) + ['notifications' => [], 'spam' => ['minSeconds' => 0]];
        self::assertSame([], $service->save($id, 'M5 test', $document)['errors']);
        return $id;
    }
    private function fields(): array
    {
        return [
            ['id' => 'quantity', 'type' => 'input', 'key' => 'quantity', 'required' => true, 'settings' => ['inputType' => 'number']],
            ['id' => 'price', 'type' => 'input', 'key' => 'price', 'settings' => ['inputType' => 'number']],
            ['id' => 'break', 'type' => 'page-break', 'label' => 'Details'],
            ['id' => 'name', 'type' => 'input', 'key' => 'name', 'required' => true],
            ['id' => 'total', 'type' => 'calculation', 'key' => 'total', 'label' => 'Total', 'settings' => ['operation' => 'product', 'sources' => [['field' => 'quantity'], ['field' => 'price']]]],
            ['id' => 'info', 'type' => 'html', 'settings' => ['content' => '<strong>Safe</strong><script>bad()</script>']],
        ];
    }
    public function testStepsValidateWithoutPersistenceAndFinalSubmissionRecomputesForgedCalculations(): void
    {
        $id = $this->form(['fields' => $this->fields()]);
        $pipeline = SubmissionService::create_default();
        $result = $pipeline->submit($id, ['quantity' => '', 'price' => '3'], ['trusted' => true, 'step' => 0]);
        self::assertSame(['quantity'], array_keys($result->errors));
        $result = $pipeline->submit($id, ['quantity' => '2', 'price' => '3'], ['trusted' => true, 'step' => 0]);
        self::assertSame('step', $result->code);
        self::assertSame(0, $result->entry_id);
        self::assertSame(0, (new \SWForms\WordPress\Entries\EntryRepository())->counts($id)['inbox']);
        self::assertSame('step', $pipeline->submit($id, [], ['trusted' => true, 'step' => 999])->code);
        self::assertArrayHasKey('quantity', swf_submit($id, ['quantity' => '', 'name' => 'Person'])->errors);
        $result = swf_submit($id, ['quantity' => '2', 'price' => '3', 'name' => 'Person', 'total' => '999999']);
        self::assertGreaterThan(0, $result->entry_id);
        $entry = (new \SWForms\WordPress\Entries\EntryRepository())->find($result->entry_id);
        self::assertSame('6.00', $entry['data']['total']);
        self::assertArrayNotHasKey('info', $entry['data']);
        $html = FormView::render($id);
        self::assertSame(2, substr_count($html, 'class="swf-step"'));
        self::assertStringContainsString('<strong>Safe</strong>', $html);
        self::assertStringNotContainsString('<script>bad', $html);
    }
    public function testAnonymousStepRestRequestNeedsAValidTokenAndDoesNotConsumeIt(): void
    {
        $id = $this->form(['fields' => $this->fields()]);
        wp_set_current_user(0);
        $body = ['swf' => ['quantity' => '2', 'price' => '3'], 'swf_step' => '0'];
        if (function_exists('wpa_field_info')) {
            $body[$GLOBALS['wpa_field_name']] = (string) wpa_field_info()['wpa_field_value'];
            $body['alt_s'] = '';
        }
        $body['swf_token'] = (new SpamGuard())->issue_token($id);
        $send = function (array $params) use ($id): \WP_REST_Response {
            $request = new \WP_REST_Request('POST', '/swforms/v1/forms/' . $id . '/submit');
            $request->set_body_params($params);
            return rest_do_request($request);
        };
        self::assertSame('step', $send($body)->get_data()['code']);
        self::assertSame('step', $send($body)->get_data()['code']);
        self::assertSame('invalid_token', $send(array_replace($body, ['swf_token' => 'forged']))->get_data()['code']);
        unset($body['swf_step']);
        $body['swf']['name'] = 'Person';
        self::assertSame('success', $send($body)->get_data()['status']);
        self::assertSame(1, (new \SWForms\WordPress\Entries\EntryRepository())->counts($id)['inbox']);
    }
    public function testSchemaRejectsNestedPageBreaksAndCalculationCycles(): void
    {
        $service = FormService::create_default();
        $fields = $this->fields();
        $fields[4]['settings']['sources'] = [['field' => 'total']];
        self::assertContains('calculation', array_column($service->validate($service->normalize(['fields' => $fields])), 'code'));
        $fields = $this->fields();
        array_unshift($fields, ['id' => 'first', 'type' => 'page-break']);
        self::assertContains('page_break', array_column($service->validate($service->normalize(['fields' => $fields])), 'code'));
        $fields = $this->fields();
        $fields[0]['logic'] = ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'total', 'op' => 'gt', 'value' => '1']]];
        self::assertContains('calculation_logic', array_column($service->validate($service->normalize(['fields' => $fields])), 'code'));
        $fields = [['id' => 'group', 'type' => 'group', 'key' => 'group', 'children' => [['id' => 'nested', 'type' => 'page-break']]]];
        self::assertContains('page_break', array_column($service->validate($service->normalize(['fields' => $fields])), 'code'));
    }
    public function testPortableExportRemovesCredentialsAndImportCreatesIndependentInactiveForm(): void
    {
        $fields = [['id' => 'password', 'type' => 'input', 'key' => 'password', 'settings' => ['inputType' => 'password', 'default' => 'private-value']]];
        $id = $this->form(['fields' => $fields, 'settings' => ['webhooks' => [['id' => 'hook', 'url' => 'https://example.com/hook?token=private-query', 'enabled' => false, 'secret' => 'signing-secret', 'headers' => [['name' => 'Authorization', 'value' => 'private-header']]]]]]);
        $export = $this->api('GET', 'forms/' . $id . '/export')->get_data();
        $json = wp_json_encode($export);
        foreach (['private-value', 'private-query', 'private-header', 'signing-secret', 'secretEncrypted', 'hasSecret'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame('', $export['document']['settings']['webhooks'][0]['url']);
        $response = $this->api('POST', 'forms/import', $export);
        self::assertSame(201, $response->get_status());
        $copy = $response->get_data()['id'];
        $this->forms[] = $copy;
        self::assertSame('inactive', (new FormRepository())->find($copy)['status']);
        self::assertNotSame((new FormRepository())->published($id)['settings']['webhooks'][0]['secretEncrypted'], (new FormRepository())->published($copy)['settings']['webhooks'][0]['secretEncrypted']);
        self::assertSame(400, $this->api('POST', 'forms/import', ['format' => 'unknown'])->get_status());
        self::assertSame(400, $this->api('POST', 'forms/import', ['format' => 'swforms', 'formatVersion' => 1, 'document' => ['fields' => 'invalid']])->get_status());
        self::assertSame(400, $this->api('POST', 'forms/import', ['oversized' => str_repeat('x', 1048577)])->get_status());
        wp_set_current_user(0);
        foreach (['forms/' . $id . '/export', 'forms/' . $id . '/versions', 'form-templates', 'forms/embed-options'] as $route) {
            self::assertSame(401, $this->api('GET', $route)->get_status());
        }
    }
    public function testHistoryRestoreKeepsPublicationAndEntryVersionIntactUntilSave(): void
    {
        $fields = [['id' => 'name', 'type' => 'input', 'key' => 'original', 'label' => 'Original']];
        $id = $this->form(['fields' => $fields]);
        $entry = swf_submit($id, ['name' => 'A']);
        $fields[0]['key'] = 'changed';
        FormService::create_default()->save($id, 'Changed', ['fields' => $fields, 'settings' => ['notifications' => []]]);
        self::assertSame([2, 1], array_column($this->api('GET', 'forms/' . $id . '/versions')->get_data()['items'], 'version'));
        self::assertSame(404, $this->api('GET', 'forms/' . $id . '/versions/99')->get_status());
        self::assertSame(200, $this->api('POST', 'forms/' . $id . '/versions/1/restore')->get_status());
        self::assertSame('changed', swf_get_form($id)['fields'][0]['key']);
        self::assertSame('original', (new FormRepository())->find($id)['draft']['fields'][0]['key']);
        self::assertSame(1, (new \SWForms\WordPress\Entries\EntryRepository())->find($entry->entry_id)['formVersion']);
        $detail = $this->api('GET', 'forms/' . $id)->get_data();
        self::assertSame('changed', ((array) $detail['publishedKeys'])['name']);
        $saved = $this->api('PUT', 'forms/' . $id, ['title' => 'Restored', 'document' => $detail['document']]);
        self::assertSame(3, $saved->get_data()['currentVersion']);
    }
    public function testTemplatesAndDynamicBlockRenderPublishedFormOnly(): void
    {
        foreach ($this->api('GET', 'form-templates')->get_data()['items'] as $template) {
            self::assertSame([], FormService::create_default()->validate(FormService::create_default()->normalize($template['document'])));
        }
        $id = $this->form(['fields' => [['id' => 'name', 'key' => 'name', 'type' => 'input', 'label' => 'Public label']]]);
        self::assertStringContainsString('Public label', render_block(['blockName' => 'swforms/form', 'attrs' => ['formId' => $id], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]));
        FormService::create_default()->save_draft($id, ['fields' => [['id' => 'name', 'key' => 'name', 'type' => 'input', 'label' => 'Private draft']]]);
        $html = render_block(['blockName' => 'swforms/form', 'attrs' => ['formId' => $id], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
        self::assertStringNotContainsString('Private draft', $html);
        $choices = $this->api('GET', 'forms/embed-options')->get_data()['items'];
        self::assertContains($id, array_column($choices, 'id'));
        self::assertSame(['id', 'title'], array_keys($choices[0]));
    }
    public function testAttachmentManifestRejectsPathsAndMailReceivesOnlyOwnedFiles(): void
    {
        $dir = wp_upload_dir()['basedir'] . '/swforms';
        wp_mkdir_p($dir);
        $name = 'swf-' . bin2hex(random_bytes(16)) . '.txt';
        file_put_contents($dir . '/' . $name, 'Owned file');
        try {
            self::assertSame([$dir . '/' . $name], UploadStorage::attachments([$name, '../wp-config.php', 'swf-unknown.txt']));
            $id = $this->form(['fields' => [], 'settings' => ['notifications' => [['id' => 'mail', 'enabled' => true, 'to' => 'test@example.test', 'attachFiles' => true]]]]);
            $repo = new \SWForms\WordPress\Entries\EntryRepository();
            $entry = $repo->insert($id, 1, [], ['uploads' => [$name]], [], '', 0);
            $form = (new FormRepository())->find($id);
            $doc = swf_get_form($id);
            $tree = \SWForms\Core\Form\Tree::build([], \SWForms\WordPress\Fields\FieldRegistry::instance());
            $submission = new \SWForms\WordPress\Submission\Submission($form, 1, $doc, $tree, [], (new \SWForms\Core\Logic\Evaluator($tree))->evaluate([]), [], $entry, $repo);
            $received = null;
            $mail = static function ($return, $atts) use (&$received) {
                $received = $atts;
                return true;
            };
            add_filter('pre_wp_mail', $mail, 10, 2);
            try {
                (new \SWForms\WordPress\Actions\EmailNotifications(\SWForms\WordPress\Fields\FieldRegistry::instance()))->run($submission);
            } finally {
                remove_filter('pre_wp_mail', $mail);
            }
            self::assertSame([$dir . '/' . $name], $received['attachments']);
        } finally {
            UploadStorage::delete([$name]);
        }
    }
}
