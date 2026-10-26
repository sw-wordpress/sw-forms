<?php

/** Integration checks for birthdates and temporal pairs. */

declare(strict_types=1);

define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 4)) . '/wp-load.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$checks;
};
$registry = \SWForms\WordPress\Fields\FieldRegistry::instance();
$fields = array(
    array( 'id' => 'birth', 'type' => 'birthdate', 'key' => 'birth', 'label' => 'Birthdate', 'required' => true, 'settings' => array( 'minAge' => 18, 'maxAge' => 65 ) ),
    array( 'id' => 'dates', 'type' => 'date-interval', 'key' => 'dates', 'label' => 'Dates', 'required' => true, 'settings' => array( 'min' => 'today', 'max' => 'today + 30 days', 'allowEqual' => false ) ),
    array( 'id' => 'times', 'type' => 'time-interval', 'key' => 'times', 'label' => 'Times', 'required' => true, 'settings' => array( 'min' => '09:00', 'max' => '17:00', 'step' => 900 ) ),
    array( 'id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Rows', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ), 'children' => array( array( 'id' => 'nested', 'type' => 'date-interval', 'key' => 'nested', 'label' => 'Nested', 'required' => true ) ) ),
    array( 'id' => 'hidden', 'type' => 'time-interval', 'key' => 'hidden', 'label' => 'Hidden', 'required' => true, 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'dates', 'op' => 'contains', 'value' => 'never' ) ) ) ),
);
$today = current_datetime();
$from = $today->modify('+1 day')->format('Y-m-d');
$to = $today->modify('+2 days')->format('Y-m-d');
$raw = array( 'birth' => $today->modify('-25 years')->format('Y-m-d'), 'dates' => array( 'from' => $from, 'to' => $to ), 'times' => array( 'from' => '09:15', 'to' => '10:30' ), 'rows' => array( 10 => array( 'nested' => array( 'from' => $from, 'to' => $to ) ) ) );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('Temporal integration');
try {
    $saved = $service->save($id, 'Temporal integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $check($saved['errors'] === array(), 'Save temporal fields');
    foreach (array( 'birthdate', 'date-interval', 'time-interval' ) as $type) {
        $check(in_array($type, array_column($registry->definitions(), 'type'), true), 'Builder definition: ' . $type);
    }
    $html = swf_render_form($id);
    $bounds = \SWForms\Core\Validation\Birthdate::bounds($today, 18, 65);
    $check(str_contains($html, 'max="' . $bounds['max'] . '"') && str_contains($html, 'min="' . $bounds['min'] . '"'), 'Birthdate HTML matches age bounds');
    $check(str_contains($html, 'name="swf[dates][from]"') && str_contains($html, 'name="swf[times][to]"'), 'Endpoint request names');
    add_filter('pre_wp_mail', '__return_true');
    $result = swf_submit($id, $raw);
    $check($result->status === 'success', 'Submit temporal fields');
    $entry = (new \SWForms\WordPress\Entries\EntryRepository())->find($result->entry_id);
    $check($entry['data']['dates'] === array( $from, $to ) && $entry['data']['times'] === array( '09:15', '10:30' ), 'Store ordered pairs');
    $check($entry['data']['birth'] === $raw['birth'] && !isset($entry['data']['hidden']), 'Birthdate string and hidden-field omission');
    $check($entry['data']['rows'][0]['nested'] === array( $from, $to ), 'Repeater pair scope');
    foreach (
        array(
        array( 'birth', $today->modify('-17 years')->format('Y-m-d') ),
        array( 'birth', $today->modify('-66 years')->format('Y-m-d') ),
        array( 'birth', $today->modify('+1 day')->format('Y-m-d') ), array( 'birth', '2023-02-29' ),
        array( 'dates', array( 'from' => $to, 'to' => $from ) ),
        array( 'dates', array( 'from' => $from, 'to' => $from ) ),
        array( 'dates', array( 'from' => $from ) ), array( 'dates', 'malformed' ),
        array( 'dates', array( 'from' => $from, 'to' => $today->modify('+31 days')->format('Y-m-d') ) ),
        array( 'times', array( 'from' => '09:01', 'to' => '10:00' ) ),
        array( 'times', array( 'from' => '23:00', 'to' => '01:00' ) ),
        array( 'times', array( 'from' => '24:00', 'to' => '24:00' ) ),
        ) as [$key, $value]
    ) {
        $r = swf_submit($id, array_replace($raw, array( $key => $value )));
        $check($r->status === 'invalid' && isset($r->errors[$key]), 'Reject invalid ' . $key);
    }
    $bad = $raw;
    $bad['rows'][20] = array( 'nested' => array( 'from' => $from ) );
    $check(isset(swf_submit($id, $bad)->errors['rows[1].nested']), 'Scoped incomplete endpoint error');
    $check($registry->get('date-interval')->format_text(array( $from, $to ), $fields[1]) === $from . ' – ' . $to, 'Readable interval entry');
    $fields[0]['settings']['validate'] = false;
    $fields[1]['settings']['validate'] = false;
    $fields[2]['settings']['validate'] = false;
    $service->save($id, 'Temporal integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $unchecked = array_replace($raw, array( 'birth' => 'free text', 'dates' => array( 'from' => 'later', 'to' => 'earlier' ), 'times' => array( 'from' => 'evening', 'to' => 'morning' ) ));
    $check(swf_submit($id, $unchecked)->status === 'success', 'Disable format and ordering validation');
    $unchecked['dates'] = array( 'from' => 'only one' );
    $check(swf_submit($id, $unchecked)->status === 'invalid', 'Completeness remains structural');
    $fields[1]['required'] = false;
    $service->save($id, 'Temporal integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $unchecked['dates'] = array();
    $check(swf_submit($id, $unchecked)->status === 'success', 'Empty optional pair allowed');
} finally {
    (new \SWForms\WordPress\Forms\FormRepository())->delete($id);
}
echo "PASS: $checks temporal field integration checks.\n";
