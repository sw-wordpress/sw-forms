<?php
/** Fixture for editable contact groups and repeated name components. */
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
if ('latest' === ($argv[1] ?? '')) {
    $repo = new \SWForms\WordPress\Entries\EntryRepository();
    $list = $repo->search((int) $argv[2], 'inbox', '', 1, 1, 'desc');
    echo wp_json_encode($repo->find((int) $list['items'][0]['id'])['data']);
    exit;
}
if ('document' === ($argv[1] ?? '')) {
    echo wp_json_encode(swf_get_form((int) $argv[2]));
    exit;
}
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E contact groups');
$document = $service->normalize(array( 'fields' => array(
    array( 'id' => 'show', 'type' => 'checkbox', 'key' => 'show', 'label' => 'Include address', 'settings' => array( 'mode' => 'single' ) ),
    array( 'id' => 'person', 'type' => 'name', 'key' => 'person', 'label' => 'Person' ),
    array( 'id' => 'location', 'type' => 'address', 'key' => 'location', 'label' => 'Address', 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'show', 'op' => 'notempty' ) ) ) ),
    array( 'id' => 'people', 'type' => 'name', 'key' => 'people', 'label' => 'People', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ) ),
), 'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => 'Contact groups saved' ) ) ));
$city = array_column($document['fields'][2]['children'], 'id', 'key')['city'];
$document['fields'][] = array( 'id' => 'note', 'type' => 'input', 'key' => 'note', 'label' => 'Berlin note', 'required' => true, 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => $city, 'op' => 'is', 'value' => 'Berlin' ) ) ) );
$result = $service->save($id, 'E2E contact groups', $document);
if ($result['errors'] !== array()) { throw new RuntimeException(wp_json_encode($result['errors'])); }
$page = wp_insert_post(array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E contact groups', 'post_content' => '[sw_form id="' . $id . '"]' ));
echo wp_json_encode(array( 'form' => $id, 'page' => $page, 'url' => get_permalink($page) )), "\n";
