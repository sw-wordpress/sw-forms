<?php
/**
 * Fixture for the webhook E2E test.
 *   setup <echo-host>      form + allowed internal host, prints {form, settings}
 *   submit <form>          submits one entry and processes its pending deliveries, prints deliveries
 *   teardown <form> <json> deletes the form and restores the allowed hosts
 */
declare(strict_types=1);

use SWForms\WordPress\Queue\Queue;
use SWForms\WordPress\Support\Settings;
use SWForms\WordPress\Webhooks\DeliveryRepository;
use SWForms\WordPress\Webhooks\DeliveryService;

define('WP_USE_THEMES', false);
define('DISABLE_WP_CRON', true);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 7)) . '/wp-load.php';
add_filter('pre_wp_mail', '__return_true');

$command = $argv[1] ?? '';
if ('teardown' === $command) {
    (new \SWForms\WordPress\Forms\FormRepository())->delete((int) $argv[2]);
    Settings::update(['webhook_allowed_hosts' => json_decode((string) $argv[3], true) ?: []]);
    Queue::cancel(DeliveryService::HOOK);
    echo "{}\n";
    exit;
}
if ('submit' === $command) {
    $id = (int) $argv[2];
    $result = swf_submit($id, ['mail' => 'max@example.org', 'name' => 'Max', 'city' => 'Berlin']);
    $deliveries = new DeliveryRepository();
    $worker = new DeliveryService();
    Queue::cancel(DeliveryService::HOOK);
    $out = [];
    foreach ($deliveries->for_entry($result->entry_id) as $delivery) {
        $worker->process($delivery['id']);
        $out[] = $deliveries->find($delivery['id']);
    }
    Queue::cancel(DeliveryService::HOOK);
    echo wp_json_encode(['entry' => $result->entry_id, 'deliveries' => $out]), "\n";
    exit;
}

$previous = Settings::all()['webhook_allowed_hosts'];
Settings::update(['webhook_allowed_hosts' => array_merge($previous, [(string) ($argv[2] ?? '127.0.0.1')])]);
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E webhooks');
$result = $service->save($id, 'E2E webhooks', [
    'fields' => [
        ['id' => 'mail', 'type' => 'input', 'key' => 'email', 'label' => 'E-Mail', 'settings' => ['inputType' => 'email']],
        ['id' => 'name', 'type' => 'input', 'key' => 'name', 'label' => 'Name'],
        ['id' => 'addr', 'type' => 'group', 'key' => 'address', 'label' => 'Adresse', 'children' => [
            ['id' => 'city', 'type' => 'input', 'key' => 'city', 'label' => 'Ort'],
        ]],
    ],
    'settings' => ['notifications' => [], 'webhooks' => []],
]);
if ($result['errors'] !== []) {
    throw new RuntimeException((string) wp_json_encode($result['errors']));
}
echo wp_json_encode(['form' => $id, 'allowed' => $previous]), "\n";
