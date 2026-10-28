<?php
/** Forms for real multipart upload tests. */
declare(strict_types=1);
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 7)) . '/wp-load.php';
if ('teardown' === ($argv[1] ?? '')) {
    (new \SWForms\WordPress\Forms\FormRepository())->delete((int) $argv[2]);
    wp_delete_post((int) $argv[3], true);
    echo "{}\n";
    exit;
}
if ('document' === ($argv[1] ?? '')) { echo wp_json_encode(swf_get_form((int) $argv[2])); exit; }
if ('count' === ($argv[1] ?? '')) {
    echo wp_json_encode(count(glob(wp_upload_dir()['basedir'] . '/swforms/swf-*') ?: []));
    exit;
}
if ('latest' === ($argv[1] ?? '')) {
    $repo = new \SWForms\WordPress\Entries\EntryRepository();
    $list = $repo->search((int) $argv[2], 'inbox', '', 1, 1, 'desc');
    $entry = $repo->find((int) $list['items'][0]['id']);
    $entry['fields'] = (new \SWForms\WordPress\Entries\EntryPresenter(\SWForms\WordPress\Fields\FieldRegistry::instance()))->present(swf_get_form((int) $argv[2])['fields'], $entry['data']);
    echo wp_json_encode($entry);
    exit;
}
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E uploads');
$file = static fn(string $id, array $extra = []): array => array_replace_recursive(['id' => $id, 'type' => 'file', 'key' => $id, 'label' => $id, 'settings' => ['extensions' => 'txt,pdf,png', 'maxSize' => 1]], $extra);
$document = ['fields' => [
    $file('single', ['required' => true]),
    $file('multi', ['settings' => ['multiple' => true, 'maxFiles' => 2]]),
    ['id' => 'note', 'type' => 'input', 'key' => 'note', 'label' => 'Consent note', 'required' => true, 'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'single', 'op' => 'is', 'value' => 'consent.txt']]]],
    $file('hidden', ['required' => true, 'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'single', 'op' => 'is', 'value' => 'reveal.txt']]]]),
    ['id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Rows', 'settings' => ['repeatable' => true, 'minRows' => 1, 'maxRows' => 3], 'children' => [$file('attachment')]],
], 'settings' => ['notifications' => [], 'spam' => ['minSeconds' => 0, 'rateLimit' => 0], 'confirmation' => ['type' => 'message', 'message' => 'Uploads saved']]];
$result = $service->save($id, 'E2E uploads', $document);
if ($result['errors'] !== []) { throw new RuntimeException(wp_json_encode($result['errors'])); }
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E uploads', 'post_content' => '[sw_form id="' . $id . '"]']);
echo wp_json_encode(['form' => $id, 'page' => $page, 'url' => get_permalink($page), 'rest' => rest_url('swforms/v1/entries/')]), "\n";
