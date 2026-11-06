<?php
/** Fixture for age checks, temporal endpoints and list conditions. */
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
$today = current_datetime();
$from = $today->modify('+1 day')->format('Y-m-d');
$to = $today->modify('+2 days')->format('Y-m-d');
$fields = array(
    array( 'id' => 'birth', 'type' => 'birthdate', 'key' => 'birth', 'label' => 'Birthdate', 'required' => true, 'settings' => array( 'minAge' => 18, 'maxAge' => 65 ) ),
    array( 'id' => 'dates', 'type' => 'date-interval', 'key' => 'dates', 'label' => 'Dates', 'required' => true, 'settings' => array( 'min' => 'today', 'max' => 'today + 30 days', 'allowEqual' => false ) ),
    array( 'id' => 'times', 'type' => 'time-interval', 'key' => 'times', 'label' => 'Times', 'required' => true, 'settings' => array( 'min' => '09:00', 'max' => '17:00', 'step' => 900 ) ),
    array( 'id' => 'note', 'type' => 'input', 'key' => 'note', 'label' => 'Interval note', 'required' => true, 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'dates', 'op' => 'contains', 'value' => $from ) ) ) ),
    array( 'id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Rows', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ), 'children' => array(
        array( 'id' => 'nested', 'type' => 'time-interval', 'key' => 'nested', 'label' => 'Nested time' ),
        array( 'id' => 'nested_choice', 'type' => 'select', 'key' => 'nested_choice', 'label' => 'Nested choice', 'settings' => array( 'searchable' => true, 'options' => array( array( 'value' => 'a', 'label' => 'Alpha' ), array( 'value' => 'b', 'label' => 'Beta' ) ) ) ),
    ) ),
);
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E temporal fields');
$result = $service->save($id, 'E2E temporal fields', array( 'fields' => $fields, 'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => 'Temporal fields saved' ) ) ));
if ($result['errors'] !== array()) { throw new RuntimeException(wp_json_encode($result['errors'])); }
$page = wp_insert_post(array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E temporal fields', 'post_content' => '[sw_form id="' . $id . '"]' ));
echo wp_json_encode(array( 'form' => $id, 'page' => $page, 'url' => get_permalink($page), 'from' => $from, 'to' => $to, 'birth' => $today->modify('-25 years')->format('Y-m-d'), 'tooYoung' => $today->modify('-17 years')->format('Y-m-d') )), "\n";
