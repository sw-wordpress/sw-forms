<?php

/** Preset groups: creation, identity, validation, nesting and presentation. */

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
$service = \SWForms\WordPress\Forms\FormService::create_default();
$document = array( 'fields' => array(
    array( 'id' => 'show', 'type' => 'checkbox', 'key' => 'show', 'settings' => array( 'mode' => 'single' ) ),
    array( 'id' => 'person', 'type' => 'name', 'key' => 'person', 'label' => 'Person' ),
    array( 'id' => 'location', 'type' => 'address', 'key' => 'location', 'label' => 'Address', 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'show', 'op' => 'notempty' ) ) ) ),
    array( 'id' => 'people', 'type' => 'name', 'key' => 'people', 'label' => 'People', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 2 ) ),
), 'settings' => array( 'notifications' => array() ) );
$normalized = $service->normalize($document);
$check($normalized === $service->normalize($document), 'Shorthand generates deterministic identities');
$check($normalized === $service->normalize($normalized), 'Normalization is idempotent');
$name = $normalized['fields'][1];
$address = $normalized['fields'][2];
$people = $normalized['fields'][3];
$ids = static fn (array $field): array => array_column($field['children'], 'id', 'key');
$n = $ids($name);
$a = $ids($address);
$p = $ids($people);
$check(array_keys($n) === array( 'salutation', 'given_name', 'family_name', 'title' ), 'Name component keys');
$check(array_keys($a) === array( 'street', 'house_number', 'postal_code', 'city', 'country' ), 'Address component keys');
$check(count(array_unique(array_merge(array_values($n), array_values($a), array_values($p)))) === 13, 'Distinct ids across presets');
$custom = $normalized;
array_pop($custom['fields'][1]['children']);
$custom['fields'][1]['children'][1]['label'] = 'Custom given name';
$custom['fields'][1]['children'][1]['required'] = false;
$again = $service->normalize($custom);
$check(count($again['fields'][1]['children']) === 3 && $again['fields'][1]['children'][1]['label'] === 'Custom given name', 'Preserve edits and deletions');
$check($again['fields'][1]['children'][1]['id'] === $n['given_name'] && $again['fields'][1]['children'][1]['required'] === false, 'Preserve identity and required setting');
$empty = $normalized;
$empty['fields'][1]['children'] = array();
$check($service->normalize($empty)['fields'][1]['children'] === array(), 'Explicit empty children stay empty');
$definitions = array_column($registry->definitions(), null, 'type');
$check(isset($definitions['name']['initialChildren'][0]['settings']['maxLength']), 'Builder receives complete settings');
$check(!isset($definitions['name']['initialChildren'][0]['id']), 'Metadata never reuses child ids');
$check(!isset($definitions['group']['initialChildren']), 'Ordinary groups remain empty');
$id = $service->create('Contact preset integration');
try {
    $saved = $service->save($id, 'Contact preset integration', $normalized);
    $check($saved['errors'] === array(), 'Save contact presets');
    $raw = array( $n['given_name'] => 'Ada', $n['family_name'] => 'Lovelace', 'people' => array( 9 => array( $p['given_name'] => 'Grace', $p['family_name'] => 'Hopper' ) ) );
    add_filter('pre_wp_mail', '__return_true');
    $result = swf_submit($id, $raw);
    $check($result->status === 'success', 'Hidden required address components skipped');
    $entries = new \SWForms\WordPress\Entries\EntryRepository();
    $entry = $entries->find($result->entry_id);
    $check($entry['data']['person']['given_name'] === 'Ada' && $entry['data']['people'][0]['family_name'] === 'Hopper', 'Nested name objects');
    $check(!isset($entry['data']['location']), 'Hidden address omitted');
    $raw['show'] = '1';
    $check(swf_submit($id, $raw)->status === 'invalid', 'Address component requirements');
    $raw += array( $a['street'] => 'Main Street', $a['house_number'] => '12A', $a['postal_code'] => '00123', $a['city'] => 'Berlin', $a['country'] => 'Deutschland' );
    $result = swf_submit($id, $raw);
    $check($result->status === 'success', 'Complete address submitted');
    $entry = $entries->find($result->entry_id);
    $check($entry['data']['location']['postal_code'] === '00123' && $entry['data']['location']['house_number'] === '12A', 'Postal and house number stay text');
    $bad = $raw;
    $bad[$n['given_name']] = str_repeat('x', 121);
    $check(isset(swf_submit($id, $bad)->errors[$n['given_name']]), 'Existing length validation reused');
    $bad = $raw;
    $bad['people'][17] = array( $p['given_name'] => 'Second' );
    $check(isset(swf_submit($id, $bad)->errors['people[1].' . $p['family_name']]), 'Repeater component error scope');
    $tree = \SWForms\Core\Form\Tree::build($saved['document']['fields'], $registry);
    $values = (new \SWForms\WordPress\Submission\ValueCollector($tree, $registry))->collect($raw);
    $visibility = (new \SWForms\Core\Logic\Evaluator($tree))->evaluate($values);
    $form = $service->repository()->find($id);
    $submission = new \SWForms\WordPress\Submission\Submission($form, $form['current_version'], $saved['document'], $tree, $values, $visibility, $entry['data'], $result->entry_id, $entries);
    $resolver = new \SWForms\WordPress\Template\MergeTagResolver($submission, $registry);
    $check($resolver->replace('{field:person.given_name} / {field:location.postal_code}', 'text') === 'Ada / 00123', 'Component merge tags');
    $check(str_contains($resolver->replace('{all_fields}', 'html'), 'Lovelace'), 'Hierarchical entry/notification presentation');
    $html = swf_render_form($id);
    $check(str_contains($html, 'autocomplete="given-name"') && str_contains($html, 'autocomplete="postal-code"'), 'Native autocomplete hints');
    $check(str_contains($html, 'swf-field--name') && str_contains($html, 'swf-field--address'), 'Preset group rendering');
} finally {
    $service->repository()->delete($id);
}
echo "PASS: $checks contact group integration checks.\n";
