<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Entries\EntryEditor;
use SWForms\WordPress\Entries\CsvExport;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Submission\SubmissionService;
use SWForms\WordPress\Submission\SpamGuard;
use SWForms\WordPress\Support\Privacy;
use SWForms\WordPress\Spam\CaptchaSettings;

final class MilestoneThreeTest extends TestCase
{
    private int $user;
    /** @var list<int> */
    private array $forms = [];
    private mixed $captcha;
    protected function setUp(): void
    {
        $this->user = get_current_user_id();
        wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
        rest_get_server();
        $this->captcha = get_option(CaptchaSettings::OPTION, null);
        add_filter('pre_wp_mail', '__return_true');
    }
    protected function tearDown(): void
    {
        foreach ($this->forms as $id) {
            (new FormRepository())->delete($id);
        }
        if ($this->captcha === null) {
            delete_option(CaptchaSettings::OPTION);
        } else {
            update_option(CaptchaSettings::OPTION, $this->captcha);
        }
        remove_filter('pre_wp_mail', '__return_true');
        wp_set_current_user($this->user);
    }
    /** @param array<string,mixed> $doc */
    private function form(array $doc): int
    {
        $service = FormService::create_default();
        $id = $service->create('M3 test');
        $this->forms[] = $id;
        self::assertSame([], $service->save($id, 'M3 test', $doc)['errors']);
        return $id;
    }
    /** @param array<string,mixed> $body */
    private function api(string $method, string $path, array $body = []): \WP_REST_Response
    {
        $r = new \WP_REST_Request($method, '/swforms/v1/' . $path);
        $r->set_header('Content-Type', 'application/json');
        $r->set_body((string) wp_json_encode($body));
        return rest_do_request($r);
    }
    /** @return array<string,mixed> */
    private function doc(): array
    {
        return ['fields' => [['id' => 'email', 'type' => 'input', 'key' => 'email', 'label' => 'Email', 'settings' => ['inputType' => 'email']], ['id' => 'name', 'type' => 'input', 'key' => 'name', 'required' => true]], 'settings' => ['notifications' => [], 'spam' => ['minSeconds' => 0]]];
    }
    public function testCsvUsesFilteredSearchAndOldKeysWithoutLeakingOtherEntries(): void
    {
        $id = $this->form($this->doc());
        $repo = new EntryRepository();
        $first = $repo->insert($id, 1, ['old' => '=HYPERLINK("x")', 'people' => [['name' => 'Mäx']]], [], [['field' => 'name', 'row' => '', 'value' => 'needle']], '', 0);
        $repo->insert($id, 1, ['name' => 'excluded'], [], [['field' => 'name', 'row' => '', 'value' => 'other']], '', 0);
        $repo->insert($id, 1, ['name' => 'temporary'], [], [['field' => 'name', 'row' => '', 'value' => 'needle']], '', 0, true);
        $repo->update($first, ['starred' => true]);
        $stream = fopen('php://temp', 'w+b');
        (new CsvExport())->write($stream, $id, 'starred', 'needle');
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        $row = fgetcsv($stream, 0, ',', '"', '');
        self::assertSame(['entry_id', 'created_at', 'status', 'data.old', 'data.people'], $header);
        self::assertSame("'=HYPERLINK(\"x\")", $row[3]);
        self::assertSame('[{"name":"Mäx"}]', $row[4]);
        self::assertFalse(fgetcsv($stream));
        fclose($stream);
        self::assertSame('text/csv; charset=UTF-8', $this->api('GET', 'forms/' . $id . '/entries/export')->get_headers()['Content-Type']);
        wp_set_current_user(0);
        self::assertSame(401, $this->api('GET', 'forms/' . $id . '/entries/export')->get_status());
    }
    public function testConditionalRequiredDisabledAndNoJsRendering(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/logic/effects.json'), true);
        $doc = ['fields' => $fixture['fields'], 'settings' => ['notifications' => []]];
        $id = $this->form($doc);
        $r = swf_submit($id, ['toggle' => '1', 'child' => 'forged', 'required' => '', 'rows' => [[]]]);
        self::assertSame('invalid', $r->status);
        self::assertArrayHasKey('required', $r->errors);
        $r = swf_submit($id, ['toggle' => '1', 'child' => 'forged', 'required' => 'ok', 'rows' => [[]]]);
        self::assertSame('success', $r->status, (string) wp_json_encode($r->errors));
        $data = (new EntryRepository())->find($r->entry_id)['data'];
        self::assertArrayNotHasKey('group', $data);
        self::assertArrayNotHasKey('dependent', $data);
        $raw = ['toggle' => true, 'required' => 'ok', 'child' => 'forged'];
        $tree = \SWForms\Core\Form\Tree::build($doc['fields'], \SWForms\WordPress\Fields\FieldRegistry::instance());
        $renderer = new \SWForms\WordPress\Frontend\Renderer(\SWForms\WordPress\Fields\FieldRegistry::instance(), $tree);
        $ctx = new \SWForms\WordPress\Frontend\RenderContext($renderer, $id, $raw, [], (new \SWForms\Core\Logic\Evaluator($tree))->evaluate($raw));
        $html = $renderer->render_ids($tree->rootIds(), $ctx);
        self::assertStringContainsString('data-swf-logic-disabled="1"', $html);
        self::assertStringContainsString('aria-required="true"', $html);
    }
    public function testEntryEditsValidateOriginalVersionPreserveUploadsAndRejectStaleChanges(): void
    {
        $doc = $this->doc();
        $doc['fields'][] = ['id' => 'files', 'type' => 'file', 'key' => 'files'];
        $id = $this->form($doc);
        $repo = new EntryRepository();
        $files = [['name' => 'original.pdf', 'url' => 'https://example.org/original.pdf', 'size' => 20, 'type' => 'application/pdf']];
        $entry = $repo->insert($id, 1, ['email' => 'old@example.org', 'name' => 'Max', 'files' => $files], [], [], '', 0);
        $edit = $this->api('GET', 'entries/' . $entry . '/values')->get_data();
        $new = $doc;
        $new['fields'][] = ['id' => 'new', 'type' => 'input', 'key' => 'new', 'required' => true];
        FormService::create_default()->save($id, 'New version', $new);
        $values = $edit['values'];
        $values['email'] = 'invalid';
        self::assertSame(422, $this->api('PUT', 'entries/' . $entry . '/values', ['values' => $values, 'revision' => $edit['revision']])->get_status());
        $values['email'] = 'new@example.org';
        $values['name'] = 'Changed';
        $values['files'] = [['url' => 'https://evil.test/forged']];
        self::assertSame(200, $this->api('PUT', 'entries/' . $entry . '/values', ['values' => $values, 'revision' => $edit['revision']])->get_status());
        self::assertSame($files, $repo->find($entry)['data']['files']);
        self::assertSame(1, $repo->find($entry)['formVersion']);
        self::assertSame(1, $repo->search($id, 'inbox', 'Changed', 1, 20, 'desc')['total']);
        self::assertSame(409, $this->api('PUT', 'entries/' . $entry . '/values', ['values' => $values, 'revision' => $edit['revision']])->get_status());
        self::assertSame(201, $this->api('POST', 'entries/' . $entry . '/notes', ['note' => '<b>Internal</b> note'])->get_status());
        self::assertSame('Internal note', $repo->logs($entry)[1]['message']);
        self::assertSame('note', $repo->logs($entry)[1]['type']);
        wp_set_current_user(0);
        self::assertSame(401, $this->api('GET', 'entries/' . $entry . '/values')->get_status());
        self::assertSame(401, $this->api('POST', 'entries/' . $entry . '/notes', ['note' => 'bad'])->get_status());
    }
    public function testPrivacyExportAndErasureTraverseMultiplePagesAndOriginalEmailTypes(): void
    {
        $id = $this->form($this->doc());
        $repo = new EntryRepository();
        for ($i = 0; $i < 205; $i++) {
            $repo->insert($id, 1, ['email' => 'person@example.org', 'name' => 'Entry ' . $i], [], [], '', 0);
        }
        $other = $repo->insert($id, 1, ['email' => 'other@example.org', 'name' => 'person@example.org'], [], [], '', 0);
        $new = $this->doc();
        $new['fields'][0]['settings']['inputType'] = 'text';
        FormService::create_default()->save($id, 'Changed schema', $new);
        $text = $repo->insert($id, 2, ['email' => 'person@example.org', 'name' => 'plain text email'], [], [], '', 0);
        $privacy = new Privacy();
        $exported = [];
        for ($page = 1; $page < 30; $page++) {
            $r = $privacy->export('PERSON@example.org', $page);
            $exported = array_merge($exported, $r['data']);
            if ($r['done']) {
                break;
            }
        }
        self::assertCount(205, $exported);
        for ($page = 1; $page < 30; $page++) {
            $r = $privacy->erase('person@example.org', $page);
            if ($r['done']) {
                break;
            }
        }
        self::assertTrue($r['done']);
        self::assertNotNull($repo->find($other));
        self::assertNotNull($repo->find($text));
        self::assertSame(2, $repo->counts($id)['inbox']);
        self::assertSame([], $privacy->export('')['data']);
    }
    public function testRawMergeTagsAreEscapedAndFormUrlWorksWithoutEntryStorage(): void
    {
        $doc = $this->doc();
        $doc['fields'][] = ['id' => 'choice', 'type' => 'select', 'key' => 'choice', 'settings' => ['options' => [['label' => 'Human label', 'value' => 'raw-value']]]];
        $doc['settings']['storeEntries'] = false;
        $doc['settings']['confirmation'] = ['type' => 'message', 'message' => '{field:choice} / {field:choice:raw} / {form:url} / {entry:ip} / {field:name:raw}'];
        $id = $this->form($doc);
        $r = SubmissionService::create_default()->submit($id, ['name' => 'A & B', 'email' => 'max@example.org', 'choice' => 'raw-value'], ['trusted' => true, 'source_url' => 'https://example.org/form?x=1&y=2', 'ip' => '192.0.2.1']);
        self::assertSame('success', $r->status, (string) wp_json_encode($r->errors));
        self::assertSame(0, $r->entry_id);
        self::assertStringContainsString('Human label / raw-value', $r->message);
        self::assertStringContainsString('https://example.org/form?x=1&amp;y=2', $r->message);
        self::assertStringContainsString('A &amp; B', $r->message);
        self::assertStringNotContainsString('192.0.2.1', $r->message);
    }
    public function testCaptchaSecretsAreWriteOnlyAndServerVerificationFailsClosed(): void
    {
        CaptchaSettings::update(['turnstile' => ['siteKey' => 'site', 'secret' => 'secret-value'], 'hcaptcha' => ['siteKey' => 'h-site', 'secret' => 'h-secret']]);
        $settings = $this->api('GET', 'settings')->get_data();
        self::assertStringNotContainsString('secret-value', (string) wp_json_encode($settings));
        self::assertStringNotContainsString('secretEncrypted', (string) wp_json_encode($settings));
        self::assertStringNotContainsString('secret-value', (string) wp_json_encode(CaptchaSettings::all()));
        $calls = [];
        $valid = true;
        $filter = static function ($pre, $args, $url) use (&$calls, &$valid) {
            if (!str_contains($url, 'siteverify')) {
                return $pre;
            }
            $calls[] = $args['body'];
            return ['headers' => [], 'body' => wp_json_encode(['success' => $valid, 'hostname' => wp_parse_url(home_url(), PHP_URL_HOST), 'action' => 'swforms']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try {
            foreach (['turnstile', 'hcaptcha'] as $provider) {
                $doc = $this->doc();
                $doc['settings']['spam']['provider'] = $provider;
                $id = $this->form($doc);
                $request = ['token' => (new SpamGuard())->issue_token($id), 'ip' => '192.0.2.1'];
                $raw = ['name' => 'Max', 'email' => 'max@example.org'];
                self::assertSame('captcha', SubmissionService::create_default()->submit($id, $raw, $request)->code);
                $valid = false;
                $request['captcha'] = 'bad-token';
                self::assertSame('captcha', SubmissionService::create_default()->submit($id, $raw, $request)->code);
                $valid = true;
                $request['captcha'] = 'valid-token';
                $r = SubmissionService::create_default()->submit($id, $raw, $request);
                self::assertSame('success', $r->status, (string) wp_json_encode($r->errors));
                self::assertSame(['name' => 'Max', 'email' => 'max@example.org'], array_reverse((new EntryRepository())->find($r->entry_id)['data'], true));
                self::assertArrayNotHasKey('remoteip', $calls[array_key_last($calls)]);
                self::assertStringNotContainsString('valid-token', (string) wp_json_encode((new EntryRepository())->find($r->entry_id)));
            }
        } finally {
            remove_filter('pre_http_request', $filter);
        }
    }
}
