<?php

/** Integration checks for choice presentations and option sanitization. */

declare(strict_types=1);

define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 4)) . '/wp-load.php';
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($name);
    }
    ++$checks;
};
$registry = \SWForms\WordPress\Fields\FieldRegistry::instance();
$options = array( array( 'label' => 'A', 'value' => 'a', 'image' => 'https://example.test/a.png' ), array( 'label' => 'B', 'value' => 'b', 'image' => 'javascript:alert(1)' ) );
$normalized = \SWForms\WordPress\Fields\SettingsNormalizer::options($options);
$check($normalized[0]['image'] === 'https://example.test/a.png', 'Retain image URL');
$check($normalized[1]['image'] === '', 'Reject script URL');
$check(\SWForms\WordPress\Fields\SettingsNormalizer::options(array( array( 'label' => 'A', 'value' => 'a' ) )) === array( array( 'label' => 'A', 'value' => 'a' ) ), 'Keep legacy option shape');
$fields = array(
    array( 'id' => 'toggle', 'type' => 'checkbox', 'key' => 'toggle', 'label' => 'Toggle', 'required' => true, 'settings' => array( 'mode' => 'single', 'switch' => true ) ),
    array( 'id' => 'buttons', 'type' => 'radio', 'key' => 'buttons', 'label' => 'Buttons', 'required' => true, 'settings' => array( 'presentation' => 'buttons', 'options' => $options ) ),
    array( 'id' => 'images', 'type' => 'checkbox', 'key' => 'images', 'label' => 'Images', 'settings' => array( 'presentation' => 'images', 'options' => $options, 'minSelected' => 1, 'maxSelected' => 1 ), 'required' => true ),
    array( 'id' => 'select', 'type' => 'select', 'key' => 'select', 'label' => 'Select', 'settings' => array( 'searchable' => true, 'multiple' => true, 'options' => $options ), 'required' => true ),
);
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('Choice integration');
try {
    $saved = $service->save($id, 'Choice integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $check($saved['errors'] === array(), 'Save variants');
    $document = swf_get_form($id);
    $check($document['fields'][2]['settings']['options'][0]['image'] === 'https://example.test/a.png', 'Persist image URL');
    $html = swf_render_form($id);
    foreach (array( 'role="switch"', 'swf-choices--buttons', 'swf-choices--images', 'alt=""', 'data-swf-search', 'No matching options.' ) as $markup) {
        $check(str_contains($html, $markup), 'Markup: ' . $markup);
    }
    $check(!str_contains($html, 'javascript:'), 'No unsafe image markup');
    $raw = array( 'toggle' => '1', 'buttons' => 'a', 'images' => array( 'a' ), 'select' => array( 'a', 'b' ) );
    add_filter('pre_wp_mail', '__return_true');
    $result = swf_submit($id, $raw);
    $check($result->status === 'success', 'Submit variants');
    $entry = (new \SWForms\WordPress\Entries\EntryRepository())->find($result->entry_id);
    $check($entry['data']['toggle'] === true && $entry['data']['images'] === array( 'a' ) && $entry['data']['select'] === array( 'a', 'b' ), 'Preserve boolean and list values');
    foreach (array( 'toggle' => '', 'buttons' => 'forged', 'images' => array( 'a', 'b' ), 'select' => array( 'forged' ) ) as $key => $invalid) {
        $check(swf_submit($id, array_replace($raw, array( $key => $invalid )))->status === 'invalid', 'Validation: ' . $key);
    }
    $check($registry->get('checkbox')->format_text(array( 'a' ), $fields[2]) === 'A', 'Entry labels');
} finally {
    (new \SWForms\WordPress\Forms\FormRepository())->delete($id);
}
echo "PASS: $checks choice field integration checks.\n";
