<?php
/** Fixture for choice presentations, search and repeater controls. */
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
$options = array( array( 'label' => 'Alpha', 'value' => 'a', 'image' => home_url('/wp-includes/images/blank.gif') ), array( 'label' => 'Beta', 'value' => 'b' ), array( 'label' => 'Gamma', 'value' => 'c' ) );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E choices');
$result = $service->save($id, 'E2E choices', array(
    'fields' => array(
        array( 'id' => 'toggle', 'type' => 'checkbox', 'key' => 'toggle', 'label' => 'Consent switch', 'required' => true, 'settings' => array( 'mode' => 'single', 'switch' => true ) ),
        array( 'id' => 'buttons', 'type' => 'radio', 'key' => 'buttons', 'label' => 'Buttons', 'required' => true, 'settings' => array( 'presentation' => 'buttons', 'options' => $options ) ),
        array( 'id' => 'images', 'type' => 'checkbox', 'key' => 'images', 'label' => 'Images', 'required' => true, 'settings' => array( 'presentation' => 'images', 'options' => $options ) ),
        array( 'id' => 'search', 'type' => 'select', 'key' => 'search', 'label' => 'Search', 'required' => true, 'settings' => array( 'multiple' => true, 'searchable' => true, 'options' => $options ) ),
        array( 'id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Rows', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ), 'children' => array(
            array( 'id' => 'nested', 'type' => 'select', 'key' => 'nested', 'label' => 'Nested', 'settings' => array( 'searchable' => true, 'options' => $options ) ),
        ) ),
    ),
    'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => 'Choices saved' ) ),
));
if ($result['errors'] !== array()) {
    throw new RuntimeException(wp_json_encode($result['errors']));
}
$page = wp_insert_post(array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E choices', 'post_content' => '[sw_form id="' . $id . '"]' ));
echo wp_json_encode(array( 'form' => $id, 'page' => $page, 'url' => get_permalink($page) )), "\n";
