<?php
/** Matrix fixture for AJAX, postback, repeaters and builder round trips. */
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
$matrix = array( 'id' => 'matrix', 'type' => 'matrix', 'key' => 'feedback', 'label' => 'Experience', 'required' => true );
$fields = array(
    $matrix,
    array( 'id' => 'note', 'type' => 'input', 'key' => 'note', 'label' => 'Tell us more', 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'matrix', 'op' => 'is', 'value' => '5' ) ) ) ),
    array( 'id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Other experiences', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ), 'children' => array( array_merge($matrix, array( 'id' => 'nested', 'required' => false )) ) ),
);
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E matrix');
$result = $service->save($id, 'E2E matrix', array( 'fields' => $fields, 'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => 'Matrix saved' ) ) ));
if ($result['errors'] !== array()) { throw new RuntimeException(wp_json_encode($result['errors'])); }
$page = wp_insert_post(array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E matrix', 'post_content' => '[sw_form id="' . $id . '"]' ));
echo wp_json_encode(array( 'form' => $id, 'page' => $page, 'url' => get_permalink($page) )), "\n";
