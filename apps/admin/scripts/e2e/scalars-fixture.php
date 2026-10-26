<?php
/** Fixture for scalar controls and list-valued tags. */
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
$fields = array();
foreach (array( 'range', 'rating', 'scale', 'color', 'tags' ) as $type) {
    $fields[] = array( 'id' => $type, 'type' => $type, 'key' => $type, 'label' => $type, 'required' => true );
}
$fields[0]['settings'] = array( 'min' => 0, 'max' => 10, 'step' => 1, 'default' => 2 );
$fields[3]['settings'] = array( 'default' => '#336699' );
$fields[] = array( 'id' => 'note', 'type' => 'input', 'key' => 'note', 'label' => 'Alpha note', 'required' => true, 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'tags', 'op' => 'contains', 'value' => 'alpha' ) ) ) );
$fields[] = array( 'id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Rows', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ), 'children' => array( array( 'id' => 'nested', 'type' => 'tags', 'key' => 'nested', 'label' => 'Nested tags' ) ) );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E scalar fields');
$result = $service->save($id, 'E2E scalar fields', array( 'fields' => $fields, 'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => 'Scalar fields saved' ) ) ));
if ($result['errors'] !== array()) { throw new RuntimeException(wp_json_encode($result['errors'])); }
$page = wp_insert_post(array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E scalar fields', 'post_content' => '[sw_form id="' . $id . '"]' ));
echo wp_json_encode(array( 'form' => $id, 'page' => $page, 'url' => get_permalink($page) )), "\n";
