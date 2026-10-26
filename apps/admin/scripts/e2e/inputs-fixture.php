<?php
/** Fixture for enhanced input validation and scoped auxiliary controls. */
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
$email = static fn (string $id): array => array( 'id' => $id, 'type' => 'input', 'key' => $id, 'label' => $id, 'required' => true, 'settings' => array( 'inputType' => 'email', 'emailConfirmation' => true ) );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('E2E input options');
$result = $service->save($id, 'E2E input options', array(
    'fields' => array(
        $email('email'),
        array( 'id' => 'phone', 'type' => 'input', 'key' => 'phone', 'label' => 'Phone', 'settings' => array( 'inputType' => 'tel', 'countryCode' => true, 'defaultCountryCode' => '+49' ) ),
        array( 'id' => 'matched', 'type' => 'input', 'key' => 'matched', 'label' => 'Phone matched', 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'phone', 'op' => 'is', 'value' => '+49 30 123456' ) ) ) ),
        array( 'id' => 'password', 'type' => 'input', 'key' => 'password', 'label' => 'Password', 'required' => true, 'settings' => array( 'inputType' => 'password', 'passwordStrength' => 'mixed', 'passwordLength' => 12 ) ),
        array( 'id' => 'date', 'type' => 'input', 'key' => 'date', 'label' => 'Date', 'required' => true, 'settings' => array( 'inputType' => 'date', 'minDate' => 'today + 1 day', 'maxDate' => 'today + 14 days' ) ),
        array( 'id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Rows', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ), 'children' => array( $email('nested') ) ),
    ),
    'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => 'Inputs saved' ) ),
));
if ($result['errors'] !== array()) {
    throw new RuntimeException(wp_json_encode($result['errors']));
}
$page = wp_insert_post(array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E input options', 'post_content' => '[sw_form id="' . $id . '"]' ));
echo wp_json_encode(array( 'form' => $id, 'page' => $page, 'url' => get_permalink($page), 'min' => current_datetime()->modify('+1 day')->format('Y-m-d'), 'date' => current_datetime()->modify('+2 days')->format('Y-m-d') )), "\n";
