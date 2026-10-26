<?php

/** Integration checks for confirmation, calling codes, passwords and relative date bounds. */

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
$input = \SWForms\WordPress\Fields\FieldRegistry::instance()->get('input');
$codes = array( array( 'label' => 'Germany', 'value' => '+49' ), array( 'label' => 'Austria', 'value' => '+43' ), array( 'label' => 'Invalid', 'value' => '+x' ) );
$phone = array( 'id' => 'phone', 'type' => 'input', 'key' => 'phone', 'label' => 'Phone', 'settings' => $input->normalize_settings(array( 'inputType' => 'tel', 'countryCode' => true, 'countryCodes' => $codes, 'defaultCountryCode' => '+49' )) );
$check(count($phone['settings']['countryCodes']) === 2, 'Reject malformed country codes');
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/phone-values.json'), true) as $fixture) {
    $check($input->collect(array( 'phone' => $fixture['value'], 'phone:country' => $fixture['country'] ), $phone) === $fixture['expected'], 'Shared phone fixture');
}
$email = static fn (string $id): array => array( 'id' => $id, 'type' => 'input', 'key' => $id, 'label' => $id, 'required' => true, 'settings' => array( 'inputType' => 'email', 'emailConfirmation' => true ) );
$fields = array(
    $email('mail'), $phone,
    array( 'id' => 'password', 'type' => 'input', 'key' => 'password', 'label' => 'Password', 'required' => true, 'settings' => array( 'inputType' => 'password', 'passwordStrength' => 'mixed', 'passwordLength' => 12 ) ),
    array( 'id' => 'date', 'type' => 'input', 'key' => 'date', 'label' => 'Date', 'required' => true, 'settings' => array( 'inputType' => 'date', 'minDate' => 'today + 1 day', 'maxDate' => 'today + 14 days' ) ),
    array( 'id' => 'rows', 'type' => 'group', 'key' => 'rows', 'label' => 'Rows', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 3 ), 'children' => array( $email('rmail') ) ),
    $email('hiddenmail') + array( 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'phone', 'op' => 'is', 'value' => 'never' ) ) ) ),
);
$tomorrow = current_datetime()->modify('+2 days')->format('Y-m-d');
$raw = array( 'mail' => 'a@example.org', 'mail:confirm' => 'a@example.org', 'phone' => '30 123456', 'phone:country' => '+49', 'password' => 'Abcdefghij7!', 'date' => $tomorrow, 'rows' => array( 8 => array( 'rmail' => 'b@example.org', 'rmail:confirm' => 'b@example.org' ) ) );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('Input options integration');
try {
    $result = $service->save($id, 'Input options integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $check($result['errors'] === array(), 'Save input options');
    $html = swf_render_form($id);
    $check(str_contains($html, 'name="swf[mail:confirm]"'), 'Render confirmation');
    $check(str_contains($html, 'name="swf[phone:country]"'), 'Render calling code');
    $check(str_contains($html, 'min="' . current_datetime()->modify('+1 day')->format('Y-m-d') . '"'), 'Resolve HTML minimum');
    add_filter('pre_wp_mail', '__return_true');
    $result = swf_submit($id, $raw);
    $check($result->status === 'success', 'Valid input options submission');
    $entry = (new \SWForms\WordPress\Entries\EntryRepository())->find($result->entry_id);
    $check($entry['data']['phone'] === '+49 30 123456', 'Store combined calling code and number');
    $check($entry['data']['mail'] === 'a@example.org' && $entry['data']['rows'][0]['rmail'] === 'b@example.org', 'Store only primary emails');
    $check(!str_contains(wp_json_encode($entry['data']), ':confirm') && !str_contains(wp_json_encode($entry['data']), ':country'), 'Auxiliary controls are not stored');
    foreach (array( 'mail:confirm' => 'different@example.org', 'phone:country' => '+9999', 'password' => 'weak', 'date' => current_datetime()->format('Y-m-d') ) as $key => $value) {
        $r = swf_submit($id, array_replace($raw, array( $key => $value )));
        $check($r->status === 'invalid', 'Reject invalid ' . $key);
    }
    $missing = $raw;
    unset($missing['mail:confirm']);
    $check(swf_submit($id, $missing)->status === 'invalid', 'Missing confirmation rejected');
    $r = swf_submit($id, array_replace($raw, array( 'mail:confirm' => array( 'a@example.org' ) )));
    $check($r->status === 'invalid', 'Array confirmation rejected');
    $nested = $raw;
    $nested['rows'][19] = array( 'rmail' => 'c@example.org', 'rmail:confirm' => 'wrong@example.org' );
    $r = swf_submit($id, $nested);
    $check(isset($r->errors['rows[1].rmail']), 'Repeater confirmation uses normalized row scope');
    $check(!isset($r->errors['hiddenmail']), 'Hidden confirmation is skipped');
    $check(!str_contains(wp_json_encode($r->values), ':confirm'), 'Auxiliary values not exposed in errors');
    $password = $fields[2];
    $check($input->collect(array( 'password' => ' Abcdefghij7! ' ), $password) === ' Abcdefghij7! ', 'Preserve password whitespace');
    $check($input->validate('long passphrase', array_replace_recursive($password, array( 'settings' => array( 'passwordStrength' => 'length' ) ))) === array(), 'Length-only policy');
    $check($input->validate('weak', array_replace_recursive($password, array( 'settings' => array( 'validate' => false ) ))) === array(), 'Disabled password validation');
    $fields[0]['settings']['validate'] = false;
    $fields[2]['settings']['validate'] = false;
    $fields[3]['settings']['validate'] = false;
    $service->save($id, 'Input options integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $check(swf_submit($id, array_replace($raw, array( 'mail:confirm' => '', 'password' => 'weak', 'date' => 'not-a-date' )))->status === 'success', 'Disable new input checks');
} finally {
    (new \SWForms\WordPress\Forms\FormRepository())->delete($id);
}
echo "PASS: $checks input option integration checks.\n";
