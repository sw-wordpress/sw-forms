<?php
/** Disposable media form for real capture, crop and native fallback browser checks. */
declare(strict_types=1);
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 7)) . '/wp-load.php';
if ('teardown' === ($argv[1] ?? '')) {
    (new \SWForms\WordPress\Forms\FormRepository())->delete((int) $argv[2]);
    wp_delete_post((int) $argv[3], true);
    echo "{}\n"; exit;
}
if ('document' === ($argv[1] ?? '')) { echo wp_json_encode(swf_get_form((int) $argv[2])); exit; }
if ('latest' === ($argv[1] ?? '')) {
    $repo = new \SWForms\WordPress\Entries\EntryRepository();
    $list = $repo->search((int) $argv[2], 'inbox', '', 1, 1, 'desc');
    echo wp_json_encode($repo->find((int) $list['items'][0]['id'])); exit;
}
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E media completion');
$document = ['fields' => [
    ['id' => 'photo', 'type' => 'image', 'key' => 'photo', 'label' => 'Crop photo', 'required' => true, 'settings' => ['crop' => true]],
    ['id' => 'signature', 'type' => 'signature', 'key' => 'signature', 'label' => 'Signature', 'required' => true],
    ['id' => 'audio', 'type' => 'audio', 'key' => 'audio', 'label' => 'Audio', 'required' => true, 'settings' => ['maxSeconds' => 2]],
    ['id' => 'show', 'type' => 'checkbox', 'key' => 'show', 'label' => 'Show video', 'settings' => ['mode' => 'single', 'text' => 'Show video', 'checked' => true]],
    ['id' => 'video', 'type' => 'video', 'key' => 'video', 'label' => 'Video', 'required' => true, 'settings' => ['maxSeconds' => 2], 'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'show', 'op' => 'notempty']]]],
    ['id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Repeated media', 'settings' => ['repeatable' => true, 'minRows' => 1, 'maxRows' => 3], 'children' => [
        ['id' => 'initials', 'type' => 'signature', 'key' => 'initials', 'label' => 'Initials'],
        ['id' => 'clip', 'type' => 'audio', 'key' => 'clip', 'label' => 'Repeated clip', 'settings' => ['maxSeconds' => 2]],
    ]],
], 'settings' => ['notifications' => [], 'spam' => ['minSeconds' => 0, 'rateLimit' => 0], 'confirmation' => ['type' => 'message', 'message' => 'Media saved']]];
$saved = $service->save($id, 'E2E media completion', $document);
if ($saved['errors'] !== []) throw new RuntimeException(wp_json_encode($saved['errors']));
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E media completion', 'post_content' => '[sw_form id="' . $id . '"]']);
$image = imagecreatetruecolor(100, 80);
imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
echo wp_json_encode(['form' => $id, 'page' => $page, 'url' => get_permalink($page), 'png' => base64_encode($png)]), "\n";
