<?php

/**
 * Integration checks for webhooks against the local WordPress and a local echo receiver:
 * secrets, validation, queueing, signature, retries, final failure, resend, SSRF protection,
 * deliveries without stored entries and cleanup. Usage: php tests/wp-webhooks.php
 */

declare(strict_types=1);

use SWForms\Core\Webhook\Signature;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Queue\Queue;
use SWForms\WordPress\Support\Settings;
use SWForms\WordPress\Webhooks\DeliveryRepository;
use SWForms\WordPress\Webhooks\DeliveryService;
use SWForms\WordPress\Webhooks\SecretStore;

define('WP_USE_THEMES', false);
// Deliveries are processed by this script, not by a cron run in parallel.
define('DISABLE_WP_CRON', true);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 4)) . '/wp-load.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$checks;
};

// Local receiver.
$log = tempnam(sys_get_temp_dir(), 'swf-echo');
$port = 18000 + random_int(0, 999);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/webhook-echo.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['SWF_ECHO_LOG' => $log]
);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; ++$i) {
    usleep(100000);
}
$base = 'http://127.0.0.1:' . $port . '/hook';
$requests = static function () use ($log): array {
    $lines = array_filter(explode("\n", (string) file_get_contents($log)));
    return array_map(static fn (string $line): array => json_decode($line, true), array_values($lines));
};
$last = static fn (): array => array_slice($requests(), -1)[0] ?? [];

$mails = [];
add_filter('pre_wp_mail', static function ($null, array $atts) use (&$mails) {
    $mails[] = $atts;
    return true;
}, 10, 2);
$fired = ['sent' => 0, 'failed' => 0];
add_action('swf_webhook_sent', static function () use (&$fired): void {
    ++$fired['sent'];
});
add_action('swf_webhook_failed', static function () use (&$fired): void {
    ++$fired['failed'];
});

$settings = Settings::all();
$service = FormService::create_default();
$forms = new FormRepository();
$deliveries = new DeliveryRepository();
$entries = new EntryRepository();
$worker = new DeliveryService();
$id = $service->create('Webhook integration');
$copy = 0;
$fields = [
    ['id' => 'f_mail', 'type' => 'input', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'settings' => ['inputType' => 'email']],
    ['id' => 'f_name', 'type' => 'input', 'key' => 'name', 'label' => 'Name'],
    ['id' => 'g_addr', 'type' => 'group', 'key' => 'address', 'label' => 'Address', 'children' => [
        ['id' => 'f_city', 'type' => 'input', 'key' => 'city', 'label' => 'City'],
    ]],
    ['id' => 'rep', 'type' => 'group', 'key' => 'people', 'label' => 'People', 'settings' => ['repeatable' => true], 'children' => [
        ['id' => 'p_name', 'type' => 'input', 'key' => 'name', 'label' => 'Name'],
    ]],
];
$hook = static fn (string $hookId, array $extra = []): array => array_replace([
    'id' => $hookId, 'name' => $hookId, 'enabled' => true, 'url' => $base, 'method' => 'POST', 'format' => 'json',
    'envelope' => true, 'fields' => 'all', 'mapping' => [], 'headers' => [], 'logic' => null,
], $extra);
$document = static fn (array $webhooks, bool $store = true): array => [
    'fields' => $fields,
    'settings' => ['notifications' => [], 'storeEntries' => $store, 'webhooks' => $webhooks],
];
$raw = ['f_mail' => 'max@example.org', 'f_name' => 'Max', 'f_city' => 'Berlin', 'rep' => [['p_name' => 'Anna']]];
$pending = static fn (int $formId): array => $deliveries->search($formId, DeliveryRepository::PENDING, '', 1, 100)['items'];
$byHook = static function (int $formId, string $hookId) use ($deliveries): array {
    return $deliveries->search($formId, '', $hookId, 1, 1)['items'][0] ?? [];
};

try {
    Settings::update(['webhook_allowed_hosts' => ['127.0.0.1']]);

    // Validation.
    $bad = $service->save($id, 'Webhook integration', $document([
        $hook('w_url', ['url' => 'ftp://example.org']),
        $hook('w_head', ['headers' => [['name' => 'Content-Length', 'value' => '1', 'secret' => false]]]),
        $hook('w_map', ['fields' => 'custom', 'mapping' => [['field' => 'p_name', 'name' => 'x']]]),
        $hook('w_rule', ['logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'p_name', 'op' => 'is', 'value' => 'x']]]]),
    ]));
    $paths = array_column($bad['errors'], 'code', 'path');
    $check(($paths['webhook:w_url'] ?? '') === 'webhook_url', 'Reject non-http URL');
    $check(($paths['webhook:w_head'] ?? '') === 'webhook_header', 'Reject reserved header');
    $check(($paths['webhook:w_map'] ?? '') === 'webhook_mapping', 'Reject mapping into a repeater');
    $check(($paths['webhook:w_rule'] ?? '') === 'rule_unknown_field', 'Reject condition on repeater field');

    // Secrets are write-only.
    $webhooks = [
        $hook('w_json', ['secret' => 'sign-me', 'headers' => [
            ['name' => 'Authorization', 'value' => 'Bearer top-secret', 'secret' => true],
            ['name' => 'X-Source', 'value' => 'website', 'secret' => false],
        ]]),
        $hook('w_form', ['url' => $base . '?status=503', 'method' => 'PUT', 'format' => 'form', 'envelope' => false, 'fields' => 'custom', 'mapping' => [
            ['field' => 'f_mail', 'name' => 'lead.email'],
            ['field' => 'f_city', 'name' => 'city'],
        ]]),
        $hook('w_cond', ['logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'f_name', 'op' => 'is', 'value' => 'nobody']]]]),
        $hook('w_off', ['enabled' => false]),
    ];
    $result = $service->save($id, 'Webhook integration', $document($webhooks));
    $check($result['errors'] === [], 'Save webhooks: ' . wp_json_encode($result['errors']));
    $stored = (string) wp_json_encode($forms->published($id));
    $check(!str_contains($stored, 'sign-me') && !str_contains($stored, 'top-secret'), 'Secrets are not part of the document');
    $check(!str_contains((string) wp_json_encode(get_option(SecretStore::option($id))), 'top-secret'), 'Secrets are stored encrypted');
    $decorated = $service->secrets()->decorate($id, $forms->published($id));
    $check($decorated['settings']['webhooks'][0]['hasSecret'] === true && $decorated['settings']['webhooks'][0]['headers'][0]['hasValue'] === true, 'Builder sees secret flags');
    $check($decorated['settings']['webhooks'][1]['hasSecret'] === false, 'No secret on second webhook');
    // Saving again without secret values keeps them.
    $service->save($id, 'Webhook integration', $decorated);
    $check($service->secrets()->signing_secret($id, 'w_json') === 'sign-me', 'Secret kept on save without new value');

    // Submission queues the deliveries.
    $submitted = swf_submit($id, $raw);
    $check($submitted->status === 'success', 'Submission succeeds');
    $logs = $entries->logs($submitted->entry_id);
    $statuses = array_column(array_filter($logs, static fn ($l) => $l['type'] === 'webhook'), 'status', 'actionId');
    $check($statuses === ['w_json' => 'queued', 'w_form' => 'queued', 'w_cond' => 'skipped'], 'Entry log: ' . wp_json_encode($statuses));
    $check(count($pending($id)) === 2, 'Two pending deliveries');
    $json = $byHook($id, 'w_json');
    $check(Queue::scheduler() && as_has_scheduled_action(DeliveryService::HOOK, [$json['id']], Queue::GROUP) !== false, 'Delivery job queued in Action Scheduler');
    Queue::cancel(DeliveryService::HOOK);

    // JSON delivery with signature and secret header.
    $worker->process($json['id']);
    $request = $last();
    $check(($request['method'] ?? '') === 'POST', 'POST request');
    $check(Signature::verify((string) $request['headers']['x-swforms-signature'], 'sign-me', $request['body'], time()), 'Signature verifies');
    $check($request['headers']['authorization'] === 'Bearer top-secret' && $request['headers']['x-source'] === 'website', 'Custom headers sent');
    $check($request['headers']['x-swforms-event-id'] === $json['eventId'] && $request['headers']['x-swforms-event'] === 'form.submitted', 'Event headers');
    $body = json_decode($request['body'], true);
    $check($body['event'] === 'form.submitted' && $body['eventId'] === $json['eventId'] && $body['entry']['id'] === $submitted->entry_id, 'Envelope');
    $check($body['data'] === $entries->find($submitted->entry_id)['data'], 'Payload data equals entry data');
    $check($body['form']['id'] === $id && $body['form']['version'] === $forms->find($id)['current_version'], 'Form in envelope: ' . wp_json_encode($body['form']));
    $json = $deliveries->find($json['id']);
    $check($json['status'] === 'success' && $json['responseCode'] === 200 && $json['attempts'] === 1, 'Delivery recorded as success');
    $check($json['payload'] !== null, 'Payload kept while the entry exists');
    $check($fired['sent'] === 1, 'swf_webhook_sent fired');

    // Form encoded delivery that keeps failing: retries with backoff, then final failure.
    $form = $byHook($id, 'w_form');
    $worker->process($form['id']);
    $request = $last();
    parse_str($request['body'], $fieldsSent);
    $check($request['method'] === 'PUT' && $fieldsSent === ['lead' => ['email' => 'max@example.org'], 'city' => 'Berlin'], 'Form encoded mapped payload: ' . $request['body']);
    $check(!isset($request['headers']['x-swforms-signature']), 'No signature without secret');
    $form = $deliveries->find($form['id']);
    $check($form['status'] === 'pending' && $form['attempts'] === 1 && $form['responseCode'] === 503, 'First failure is retried');
    $next = strtotime((string) $form['nextAttemptAt']);
    $check($next >= time() + 50 && $next <= time() + 70, 'Next attempt after about a minute');
    $check(as_has_scheduled_action(DeliveryService::HOOK, [$form['id']], Queue::GROUP) !== false, 'Retry job scheduled');
    for ($attempt = 2; $attempt <= 6; ++$attempt) {
        $worker->process($form['id']);
    }
    Queue::cancel(DeliveryService::HOOK);
    $form = $deliveries->find($form['id']);
    $check($form['status'] === 'failed' && $form['attempts'] === 6, 'Final failure after 6 attempts');
    $check($fired['failed'] === 1, 'swf_webhook_failed fired');
    $alerts = array_filter($mails, static fn ($m) => str_contains((string) $m['subject'], 'Webhook'));
    $check(count($alerts) === 1 && str_contains((string) array_values($alerts)[0]['message'], '#/forms/' . $id . '/webhooks'), 'One alert mail with link');
    $check(($deliveries->failure_counts()[$id] ?? 0) === 1, 'Failure badge count');
    $statuses = array_count_values(array_column(array_filter($entries->logs($submitted->entry_id), static fn ($l) => $l['actionId'] === 'w_form'), 'status'));
    $check(($statuses['retry'] ?? 0) === 5 && ($statuses['failed'] ?? 0) === 1, 'Retries logged on the entry');

    // Fix the receiver, resend with the same event id.
    $webhooks[1]['url'] = $base;
    $service->save($id, 'Webhook integration', $document($webhooks));
    $check($worker->resend($form['id']), 'Resend queued');
    Queue::cancel(DeliveryService::HOOK);
    $worker->process($form['id']);
    $resent = $deliveries->find($form['id']);
    $check($resent['status'] === 'success' && $resent['attempts'] === 1 && $last()['headers']['x-swforms-event-id'] === $form['eventId'], 'Resend delivers with the same event id');
    $check(($deliveries->failure_counts()[$id] ?? 0) === 0, 'Badge cleared');

    // 4xx responses and redirects are final at once.
    $webhooks[1]['url'] = $base . '?status=302';
    $service->save($id, 'Webhook integration', $document($webhooks));
    $worker->resend($form['id']);
    Queue::cancel(DeliveryService::HOOK);
    $worker->process($form['id']);
    $redirect = $deliveries->find($form['id']);
    $check($redirect['status'] === 'failed' && $redirect['attempts'] === 1 && str_contains($redirect['message'], 'example.org/elsewhere'), 'Redirect not followed');
    $worker->dismiss($form['id']);
    $check($deliveries->find($form['id'])['status'] === 'dismissed', 'Dismiss');

    // SSRF protection without the allow list.
    Settings::update(['webhook_allowed_hosts' => []]);
    $worker->resend($json['id']);
    Queue::cancel(DeliveryService::HOOK);
    $before = count($requests());
    $worker->process($json['id']);
    $blocked = $deliveries->find($json['id']);
    $check($blocked['status'] === 'failed' && $blocked['attempts'] === 1 && count($requests()) === $before, 'Private address blocked without request');
    Settings::update(['webhook_allowed_hosts' => ['127.0.0.1']]);

    // Test button: unsaved secret, sample data.
    $test = $worker->test($id, 'Webhook integration', $hook('w_new', ['secret' => 'fresh']), $forms->published($id));
    $request = $last();
    $check($test['success'] && Signature::verify((string) $request['headers']['x-swforms-signature'], 'fresh', $request['body'], time()), 'Test request signed with unsaved secret');
    $body = json_decode($request['body'], true);
    $check($body['event'] === 'webhook.test' && $body['data']['email'] === 'jane.doe@example.org' && $body['data']['people'][0]['name'] !== '', 'Test payload with sample data');
    $check($deliveries->search($id, '', 'w_new', 1, 10)['total'] === 0, 'Test creates no delivery');

    // Without stored entries the payload is deleted after the delivery.
    $service->save($id, 'Webhook integration', $document([$webhooks[0]], false));
    $submitted = swf_submit($id, $raw);
    $check($submitted->entry_id === 0, 'Entry not stored');
    $temp = $pending($id)[0] ?? [];
    $check(($temp['entryId'] ?? -1) === 0 && $temp['payload'] !== null, 'Delivery keeps payload while queued');
    Queue::cancel(DeliveryService::HOOK);
    $worker->process($temp['id']);
    $temp = $deliveries->find($temp['id']);
    $check($temp['status'] === 'success' && $temp['payload'] === null && json_decode($last()['body'], true)['entry']['id'] === null, 'Payload removed after delivery');
    $check($worker->resend($temp['id']) === false, 'No resend without data');

    // Recovery of lost queue jobs.
    $service->save($id, 'Webhook integration', $document([$webhooks[0]]));
    $submitted = swf_submit($id, $raw);
    Queue::cancel(DeliveryService::HOOK);
    $lost = $pending($id)[0];
    global $wpdb;
    $wpdb->update(\SWForms\WordPress\Database\Schema::table('webhook_deliveries'), ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 3600)], ['id' => $lost['id']]);
    $check($worker->recover() >= 1 && as_has_scheduled_action(DeliveryService::HOOK, [$lost['id']], Queue::GROUP) !== false, 'Overdue delivery requeued');
    Queue::cancel(DeliveryService::HOOK);

    // Duplicates keep secrets, deleting entries and forms removes deliveries and secrets.
    $copy = $service->duplicate($id);
    $check($service->secrets()->signing_secret($copy, 'w_json') === 'sign-me', 'Duplicate keeps secrets');
    $entries->delete($submitted->entry_id);
    $check($deliveries->for_entry($submitted->entry_id) === [], 'Entry deletion removes deliveries');
    $forms->delete($copy);
    $check(get_option(SecretStore::option($copy), null) === null, 'Form deletion removes secrets');
    $copy = 0;
} finally {
    Queue::cancel(DeliveryService::HOOK);
    Settings::update(['webhook_allowed_hosts' => $settings['webhook_allowed_hosts']]);
    $forms->delete($id);
    if ($copy > 0) {
        $forms->delete($copy);
    }
    proc_terminate($server);
    @unlink($log);
}
$check(get_option(SecretStore::option($id), null) === null && $deliveries->search($id, '', '', 1, 1)['total'] === 0, 'Cleanup');
echo "PASS: $checks webhook integration checks.\n";
