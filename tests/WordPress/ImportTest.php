<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Import\AdapterInterface;
use SWForms\WordPress\Import\ContactForm7;
use SWForms\WordPress\Import\Registry;
use SWForms\WordPress\Import\Source;
use SWForms\WordPress\Import\WPForms;

final class ImportTest extends TestCase
{
    private int $user;
    private array $posts = [];
    private array $forms = [];

    protected function setUp(): void
    {
        $this->user = get_current_user_id();
        wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
        rest_get_server();
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $id) {
            wp_delete_post($id, true);
        }
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

    private function source(string $type, string $content = ''): int
    {
        $id = wp_insert_post(['post_type' => $type, 'post_title' => 'Importer fixture', 'post_content' => wp_slash($content), 'post_status' => 'publish']);
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        $this->posts[] = $id;
        return $id;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCatalogDoesNotAutoloadEitherAdapter(): void
    {
        self::assertFalse(class_exists(ContactForm7::class, false));
        self::assertFalse(class_exists(WPForms::class, false));
        self::assertSame(200, $this->api('GET', 'importers')->get_status());
        self::assertFalse(class_exists(ContactForm7::class, false));
        self::assertFalse(class_exists(WPForms::class, false));
        self::assertSame(200, $this->api('GET', 'importers/wpforms/forms')->get_status());
        self::assertTrue(class_exists(WPForms::class, false));
        self::assertFalse(class_exists(ContactForm7::class, false));
    }

    public function testRegistryDefersFactoriesAndChecksAvailability(): void
    {
        $registry = new Registry();
        $loaded = 0;
        $adapter = new class implements AdapterInterface {
            public function forms(int $page): array
            {
                return [];
            }
            public function read(int $id): ?array
            {
                return null;
            }
        };
        $factory = static function () use (&$loaded, $adapter): AdapterInterface {
            ++$loaded;
            return $adapter;
        };
        $registry->register('bad_slug', 'Bad', [], [], static fn () => true, $factory);
        $registry->register('test', 'Test', ['fields'], ['mail'], static fn () => true, $factory);
        $registry->register('offline', 'Offline', [], [], static fn () => false, $factory);
        self::assertCount(2, $registry->catalog());
        self::assertSame(0, $loaded);
        self::assertNull($registry->get('offline'));
        self::assertNull($registry->get('missing'));
        self::assertSame($adapter, $registry->get('test'));
        self::assertSame($adapter, $registry->get('test'));
        self::assertSame(1, $loaded);
        $registry->register('test', 'Replacement', [], [], static fn () => true, $factory);
        $registry->get('test');
        self::assertSame(2, $loaded);
    }

    public function testCatalogAndAllOperationsRequireFormManagement(): void
    {
        $catalog = $this->api('GET', 'importers');
        self::assertSame(200, $catalog->get_status());
        self::assertSame('private, no-store', $catalog->get_headers()['Cache-Control']);
        self::assertContains('contact-form-7', array_column($catalog->get_data()['items'], 'slug'));
        foreach ([0, (int) wp_create_user('swf_import_viewer_' . wp_generate_password(8, false), wp_generate_password())] as $user) {
            wp_set_current_user($user);
            if ($user > 0) {
                (new \WP_User($user))->add_cap('swf_view_entries');
            }
            try {
                foreach ([['GET', 'importers'], ['GET', 'importers/wpforms/forms'], ['GET', 'importers/wpforms/forms/1'], ['POST', 'importers/wpforms/forms/1']] as [$method, $path]) {
                    self::assertContains($this->api($method, $path)->get_status(), [401, 403]);
                }
            } finally {
                if ($user > 0) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user($user);
                }
            }
        }
    }

    public function testWPFormsDryRunConfirmationAndChangedSource(): void
    {
        $id = $this->source('wpforms', (string) wp_json_encode(['fields' => [
            ['type' => 'text', 'label' => 'Text', 'required' => '1', 'placeholder' => 'Example', 'default_value' => '{user_name}'],
            ['type' => 'email', 'label' => 'Email'],
            ['type' => 'name', 'label' => 'Name', 'format' => 'first-middle-last', 'required' => '1'],
            ['type' => 'name', 'label' => 'Simple', 'format' => 'simple', 'simple_default' => 'Max'],
            ['type' => 'select', 'label' => 'Pick', 'choices' => [['label' => 'A', 'value' => 'a']], 'show_values' => '1', 'multiple' => '1'],
            ['type' => 'checkbox', 'label' => 'Checks', 'choices' => [['label' => 'Yes']]],
            ['type' => 'radio', 'label' => 'Radio', 'choices' => [['label' => 'No']]],
            ['type' => 'gdpr-checkbox', 'label' => 'Consent', 'required' => '1', 'choices' => [1 => ['label' => 'I agree to the privacy policy']]],
            ['type' => 'textarea', 'label' => 'Message'],
            ['type' => 'payment-single', 'label' => 'Payment'],
            ['type' => 'select', 'label' => 'Dynamic', 'dynamic_choices' => 'post'],
            ['type' => 'radio', 'label' => 'Broken', 'choices' => [['bad' => true]]],
            null,
        ], 'settings' => ['submit_text' => 'Send now', 'notifications' => ['to' => 'private@example.test']]]));
        $path = 'importers/wpforms/forms/' . $id;
        $list = $this->api('GET', 'importers/wpforms/forms')->get_data()['items'];
        self::assertContains($id, array_column($list, 'id'));
        self::assertSame([], (new WPForms())->forms(10000));
        $preview = $this->api('GET', $path);
        self::assertSame(200, $preview->get_status(), (string) wp_json_encode($preview->get_data()));
        $report = $preview->get_data();
        self::assertCount(9, $report['document']['fields']);
        self::assertCount(4, $report['skipped']);
        self::assertSame('Send now', $report['document']['settings']['submitLabel']);
        self::assertSame('', $report['document']['fields'][0]['settings']['default']);
        self::assertTrue($report['document']['fields'][0]['required']);
        self::assertSame('group', $report['document']['fields'][2]['type']);
        self::assertCount(3, $report['document']['fields'][2]['children']);
        self::assertSame('Max', $report['document']['fields'][3]['settings']['default']);
        self::assertSame('a', $report['document']['fields'][4]['settings']['options'][0]['value']);
        self::assertTrue($report['document']['fields'][4]['settings']['multiple']);
        self::assertSame('I agree to the privacy policy', $report['document']['fields'][7]['settings']['text']);
        self::assertSame([], $report['document']['settings']['notifications']);
        self::assertSame(409, $this->api('POST', $path)->get_status());
        self::assertSame(409, $this->api('POST', $path, ['fingerprint' => 'forged'])->get_status());
        $response = $this->api('POST', $path, ['fingerprint' => $report['fingerprint']]);
        self::assertSame(201, $response->get_status());
        $formId = $response->get_data()['id'];
        $this->forms[] = $formId;
        $saved = (new FormRepository())->find($formId);
        self::assertSame('inactive', $saved['status']);
        self::assertSame(0, $saved['current_version']);
        self::assertSame([], $saved['draft']['settings']['notifications']);
        self::assertSame([], $saved['draft']['settings']['webhooks']);
        wp_update_post(['ID' => $id, 'post_title' => 'Changed']);
        self::assertSame(409, $this->api('POST', $path, ['fingerprint' => $report['fingerprint']])->get_status());
        self::assertSame(404, $this->api('GET', 'importers/no-such-plugin/forms')->get_status());
        self::assertSame(404, $this->api('GET', 'importers/no-such-plugin/forms/1')->get_status());
        self::assertSame(404, $this->api('POST', 'importers/no-such-plugin/forms/1')->get_status());
    }

    public function testInvalidSourcesAndUnsupportedOnlyFormsAreRejected(): void
    {
        $adapter = new WPForms();
        self::assertNull($adapter->read(0));
        self::assertNull($adapter->read($this->source('post', '{}')));
        self::assertNull($adapter->read($this->source('wpforms', '{bad')));
        self::assertNull($adapter->read($this->source('wpforms', '{}')));
        self::assertNull($adapter->read($this->source('wpforms', str_repeat('x', 1048577))));
        self::assertNull($adapter->read($this->source('wpforms', (string) wp_json_encode(['fields' => array_fill(0, 501, [])]))));
        $id = $this->source('wpforms', (string) wp_json_encode(['fields' => [['type' => 'payment']]]));
        self::assertSame(422, $this->api('GET', 'importers/wpforms/forms/' . $id)->get_status());
        self::assertSame(422, $this->api('POST', 'importers/wpforms/forms/' . $id)->get_status());
        wp_trash_post($id);
        self::assertNull($adapter->read($id));
    }

    public function testConsentTextAndMalformedChoicesAreNeverInvented(): void
    {
        $id = $this->source('wpforms', (string) wp_json_encode(['fields' => [
            ['type' => 'gdpr-checkbox', 'label' => 'Missing legal text'],
            ['type' => 'gdpr-checkbox', 'label' => 'Malformed', 'choices' => [1 => ['value' => 'x']]],
            ['type' => 'select', 'label' => 'No options', 'choices' => []],
            ['type' => 'name', 'label' => 'Name', 'first_default' => '{user_name}', 'first_placeholder' => 'First'],
            ['type' => 'phone', 'label' => 'Phone', 'default_value' => '+49123'],
        ]]));
        $report = (new WPForms())->read($id);
        self::assertCount(3, $report['skipped']);
        self::assertSame('', $report['document']['fields'][0]['children'][0]['settings']['default']);
        self::assertSame('First', $report['document']['fields'][0]['children'][0]['settings']['placeholder']);
        self::assertSame('tel', $report['document']['fields'][1]['settings']['inputType']);
    }

    public function testDatabaseFailureDoesNotReportImportSuccess(): void
    {
        global $wpdb;
        $id = $this->source('wpforms', (string) wp_json_encode(['fields' => [['type' => 'text', 'label' => 'Text']]]));
        $path = 'importers/wpforms/forms/' . $id;
        $report = $this->api('GET', $path)->get_data();
        $filter = static fn (string $sql): string => str_starts_with($sql, 'INSERT INTO `' . $wpdb->prefix . 'swf_forms`') ? 'SWF_INTENTIONAL_INVALID_SQL' : $sql;
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $response = $this->api('POST', $path, ['fingerprint' => $report['fingerprint']]);
            self::assertSame(500, $response->get_status());
            self::assertSame('swf_import_failed', $response->get_data()['code']);
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($previous);
        }
    }

    public function testContactForm7UsesNativeTagsAndReportsUnsupportedContent(): void
    {
        self::assertTrue(class_exists('WPCF7_ContactForm'), 'Run with the installed CF7 plugin active.');
        $id = $this->source('wpcf7_contact_form');
        update_post_meta($id, '_form', '<label>Your name [text* your-name placeholder "Name"]</label>
            [email email "person@example.org"] [number amount] [date date] [hidden secret "ok"]
            [textarea message "Hello"] [select* choice multiple "One|mail-one" "Two"]
            [checkbox* checks "A" "B"] [radio kind "X" "Y"]
            [acceptance consent]I agree[/acceptance] [acceptance optional optional]Optional[/acceptance]
            [acceptance inverse invert]Inverse[/acceptance] [select dynamic data:posts]
            [checkbox exclusive exclusive "A"] [radio free free_text "B"]
            [file attachment] [quiz question "Q|A"] [custom-addon addon]
            [text your_name] [select prompt first_as_label "Choose" "Yes"] [submit "Import send"]');
        $adapter = new ContactForm7();
        self::assertContains($id, array_column($adapter->forms(1), 'id'));
        self::assertNull($adapter->read(0));
        self::assertNull($adapter->read($this->source('post')));
        $report = $adapter->read($id);
        self::assertNotNull($report);
        self::assertSame('Import send', $report['document']['settings']['submitLabel']);
        self::assertSame('Your name', $report['document']['fields'][0]['label']);
        self::assertSame('Name', $report['document']['fields'][0]['settings']['placeholder']);
        self::assertTrue($report['document']['fields'][0]['required']);
        self::assertSame('One', $report['document']['fields'][6]['settings']['options'][0]['value']);
        self::assertTrue($report['document']['fields'][6]['settings']['multiple']);
        self::assertSame('Choose', $report['document']['fields'][12]['settings']['placeholder']);
        self::assertSame([['label' => 'Yes', 'value' => 'Yes']], $report['document']['fields'][12]['settings']['options']);
        self::assertContains('[custom-addon]', $report['skipped']);
        self::assertCount(7, $report['skipped']);
        $keys = array_column($report['document']['fields'], 'key');
        self::assertCount(count($keys), array_unique($keys));
        $response = $this->api('GET', 'importers/contact-form-7/forms/' . $id);
        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $tooLarge = $this->source('wpcf7_contact_form');
        update_post_meta($tooLarge, '_form', str_repeat('a', 1048577));
        self::assertNull($adapter->read($tooLarge));
        $tooMany = $this->source('wpcf7_contact_form');
        update_post_meta($tooMany, '_form', implode(' ', array_map(static fn ($i) => '[text field' . $i . ']', range(0, 500))));
        self::assertNull($adapter->read($tooMany));
    }

    public function testAdditionalAdapterCanRegisterAndDeliverySettingsAreRemoved(): void
    {
        $adapter = new class implements AdapterInterface {
            public function forms(int $page): array
            {
                return [];
            }
            public function read(int $id): ?array
            {
                $result = Source::result('Extension');
                $result['document']['fields'] = [Source::field(0, '0 bad key', 'input', '<b>Safe</b>', true, [])];
                $result['document']['settings']['notifications'] = [['id' => 'bad', 'enabled' => true]];
                $result['document']['settings']['webhooks'] = [['id' => 'bad', 'enabled' => true]];
                return $result;
            }
        };
        Registry::instance()->register('test-extension', 'Extension', [], [], static fn () => true, static fn () => $adapter);
        $report = $this->api('GET', 'importers/test-extension/forms/1')->get_data();
        self::assertSame('field_0_bad_key', $report['document']['fields'][0]['key']);
        self::assertSame('Safe', $report['document']['fields'][0]['label']);
        self::assertSame([], $report['document']['settings']['notifications']);
        self::assertSame([], $report['document']['settings']['webhooks']);
    }
}
