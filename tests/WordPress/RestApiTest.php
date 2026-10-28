<?php

declare(strict_types=1);

namespace SWForms\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Support\Capabilities;

final class RestApiTest extends TestCase
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
        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        return rest_do_request($request);
    }

    private function createForm(): int
    {
        $response = $this->request('POST', 'forms', ['title' => 'REST test']);
        self::assertSame(201, $response->get_status());
        $id = (int) $response->get_data()['id'];
        $this->forms[] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private function document(string $label = 'Name'): array
    {
        return ['fields' => [['id' => 'person', 'type' => 'input', 'key' => 'name', 'label' => $label, 'required' => true]], 'settings' => ['notifications' => []]];
    }

    public function testPublishedDocumentsRemainStableWhileDraftsAreEdited(): void
    {
        $id = $this->createForm();
        $saved = $this->request('PUT', 'forms/' . $id, ['title' => 'Contact', 'document' => $this->document()]);
        self::assertSame(200, $saved->get_status());
        self::assertSame(1, $saved->get_data()['currentVersion']);
        self::assertSame(['person' => 'name'], (array) $saved->get_data()['publishedKeys']);
        self::assertSame(200, $this->request('PUT', 'forms/' . $id . '/draft', ['document' => $this->document('Draft name')])->get_status());
        $draft = $this->request('GET', 'forms/' . $id)->get_data();
        self::assertTrue($draft['hasDraft']);
        self::assertSame('Draft name', $draft['document']['fields'][0]['label']);
        self::assertSame('Name', (new FormRepository())->published($id)['fields'][0]['label']);
        $discarded = $this->request('DELETE', 'forms/' . $id . '/draft')->get_data();
        self::assertFalse($discarded['hasDraft']);
        self::assertSame('Name', $discarded['document']['fields'][0]['label']);
        $duplicate = $this->request('POST', 'forms/' . $id . '/duplicate');
        self::assertSame(201, $duplicate->get_status());
        $this->forms[] = (int) $duplicate->get_data()['id'];
        self::assertNotSame($id, $duplicate->get_data()['id']);
        self::assertSame('Name', $duplicate->get_data()['document']['fields'][0]['label']);
        self::assertSame('inactive', $this->request('PATCH', 'forms/' . $id, ['status' => 'inactive'])->get_data()['status']);
        self::assertContains($id, array_column($this->request('GET', 'forms')->get_data()['items'], 'id'));
        self::assertSame(['deleted' => true], $this->request('DELETE', 'forms/' . $id)->get_data());
        self::assertSame(404, $this->request('GET', 'forms/' . $id)->get_status());
    }

    public function testSchemaErrorsPreserveDraftWorkWithoutReplacingThePublishedVersion(): void
    {
        $id = $this->createForm();
        $this->request('PUT', 'forms/' . $id, ['title' => 'Contact', 'document' => $this->document()]);
        $invalid = $this->document();
        $invalid['fields'][] = ['id' => 'second', 'type' => 'input', 'key' => 'name'];
        $response = $this->request('PUT', 'forms/' . $id, ['title' => 'Invalid', 'document' => $invalid]);
        self::assertSame(422, $response->get_status());
        self::assertSame('swf_invalid_form', $response->get_data()['code']);
        self::assertNotEmpty($response->get_data()['data']['errors']);
        $repo = new FormRepository();
        self::assertCount(1, $repo->published($id)['fields']);
        self::assertCount(2, $repo->find($id)['draft']['fields']);
        $invalidStatus = $this->request('PATCH', 'forms/' . $id, ['status' => 'deleted']);
        self::assertSame(400, $invalidStatus->get_status());
    }

    public function testEntrySearchPresentationUpdatesAndBulkActionsUseStoredVersions(): void
    {
        $id = $this->createForm();
        $this->request('PUT', 'forms/' . $id, ['title' => 'Entries', 'document' => $this->document()]);
        $first = swf_submit($id, ['person' => 'Ada'])->entry_id;
        $second = swf_submit($id, ['person' => 'Grace'])->entry_id;
        self::assertGreaterThan(0, $first);
        $request = new \WP_REST_Request('GET', '/swforms/v1/forms/' . $id . '/entries');
        $request->set_query_params(['search' => 'Ada']);
        $list = rest_do_request($request)->get_data();
        self::assertSame(1, $list['total']);
        self::assertSame($first, $list['items'][0]['id']);
        self::assertSame('Name', $list['columns'][0]['label']);
        $detail = $this->request('GET', 'entries/' . $first)->get_data();
        self::assertSame('Ada', $detail['fields'][0]['value']);
        self::assertSame('Name', $detail['fields'][0]['label']);
        self::assertSame('read', $this->request('PATCH', 'entries/' . $first, ['status' => 'read', 'starred' => true])->get_data()['status']);
        foreach (['star', 'unstar', 'unread', 'spam', 'trash', 'restore'] as $action) {
            $result = $this->request('POST', 'entries/bulk', ['action' => $action, 'ids' => [$first, $second, $first, 99999999]]);
            self::assertSame(['affected' => 2], $result->get_data());
        }
        self::assertSame(['deleted' => true], $this->request('DELETE', 'entries/' . $first)->get_data());
        self::assertSame(404, $this->request('GET', 'entries/' . $first)->get_status());
        self::assertSame(['affected' => 1], $this->request('POST', 'entries/bulk', ['action' => 'delete', 'ids' => [$second]])->get_data());
    }

    public function testMissingResourcesReturn404AcrossTheAdapter(): void
    {
        foreach ([['GET', 'forms/99999999'], ['PUT', 'forms/99999999'], ['PUT', 'forms/99999999/draft'], ['DELETE', 'forms/99999999/draft'], ['POST', 'forms/99999999/duplicate'], ['PATCH', 'forms/99999999'], ['DELETE', 'forms/99999999'], ['GET', 'entries/99999999'], ['PATCH', 'entries/99999999'], ['DELETE', 'entries/99999999'], ['GET', 'forms/99999999/entries']] as [$method, $path]) {
            self::assertSame(404, $this->request($method, $path, ['status' => str_starts_with($path, 'entries/') ? 'read' : 'active'])->get_status(), $method . ' ' . $path);
        }
    }

    public function testAnonymousVisitorsCannotReadOrModifyAdminResources(): void
    {
        $id = $this->createForm();
        wp_set_current_user(0);
        foreach ([['GET', 'forms'], ['GET', 'forms/' . $id], ['POST', 'forms'], ['PUT', 'forms/' . $id], ['GET', 'forms/' . $id . '/entries'], ['GET', 'field-types'], ['GET', 'settings']] as [$method, $path]) {
            self::assertContains($this->request($method, $path)->get_status(), [401, 403], $method . ' ' . $path);
        }
    }

    public function testEntryViewPermissionDoesNotGrantFormEditingOrSettingsAccess(): void
    {
        $id = $this->createForm();
        $user = new \WP_User(get_current_user_id());
        // Use an ephemeral user, leaving the administrator's roles and capabilities untouched.
        $viewer = wp_insert_user(['user_login' => 'swf-viewer-' . bin2hex(random_bytes(6)), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
        self::assertIsInt($viewer);
        $viewerUser = new \WP_User($viewer);
        $viewerUser->add_cap(Capabilities::VIEW_ENTRIES);
        wp_set_current_user($viewer);
        try {
            self::assertSame(200, $this->request('GET', 'forms')->get_status());
            self::assertSame(200, $this->request('GET', 'forms/' . $id)->get_status());
            self::assertSame(403, $this->request('POST', 'forms', ['title' => 'Forbidden'])->get_status());
            self::assertSame(403, $this->request('GET', 'settings')->get_status());
        } finally {
            wp_set_current_user($user->ID);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($viewer);
        }
    }
}
