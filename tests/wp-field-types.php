<?php

/**
 * Field registry and input integration checks against local WordPress.
 * Usage: WP_ROOT=/path/to/wordpress php tests/wp-field-types.php
 */

declare(strict_types=1);

// Register before loading WordPress, so the filter also works if another plugin initializes the registry early.
require(getenv('WP_ROOT') ?: dirname(__DIR__, 4)) . '/wp-includes/plugin.php';
add_filter('swf_field_types', static function (array $types): array {
    $types[] = new class () extends \SWForms\WordPress\Fields\AbstractFieldType {
        public function type(): string
        {
            return 'custom-test';
        }
        public function label(): string
        {
            return 'Custom test';
        }
        public function icon(): string
        {
            return 'r_star';
        }
        public function category(): string
        {
            return 'basic';
        }
        // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Implements the WordPress field interface.
        public function settings_schema(): array
        {
            return array();
        }
        public function render(array $element, \SWForms\WordPress\Frontend\RenderContext $context): string
        {
            return $this->wrap($element, $context, '<input name="' . esc_attr($context->name($element['id'])) . '">');
        }
    };
    $types[] = 'invalid entry';
    return $types;
});
$legacy_called = false;
add_action('swf_register_field_types', static function ($registry) use (&$legacy_called): void {
    if (! $registry->has('custom-test')) {
        throw new RuntimeException('Filter must precede action.');
    }
    $legacy_called = true;
});
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 4)) . '/wp-load.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
    ++$checks;
};
$registry = \SWForms\WordPress\Fields\FieldRegistry::instance();
$check($legacy_called && $registry->has('custom-test') && $registry->has('input'), 'Registry compatibility');
$check(in_array('custom-test', array_column($registry->definitions(), 'type'), true), 'Builder definitions');
$input = $registry->get('input');
$element = static fn (string $type, array $settings = array()): array => array( 'id' => 'test', 'type' => 'input', 'key' => 'value', 'label' => 'Test', 'settings' => array_merge($input->default_settings(), array( 'inputType' => $type ), $settings) );
foreach (
    array(
    array( 'time', '24:00', array(), false ),
    array( 'time', '09:30', array( 'minDate' => '09:00', 'maxDate' => '10:00', 'timeStep' => 900 ), true ),
    array( 'time', '09:31', array( 'timeStep' => 900 ), false ),
    array( 'time', '09:00:00', array( 'minDate' => '09:00' ), true ),
    array( 'time', '10:00', array( 'maxDate' => '10:00:00' ), true ),
    array( 'time', '08:00', array( 'minDate' => '09:00' ), false ),
    array( 'date', '2024-02-29', array( 'minDate' => '2024-02-01', 'maxDate' => '2024-03-01' ), true ),
    array( 'date', '2023-02-29', array(), false ),
    array( 'datetime-local', '2024-02-29T10:30', array( 'minDate' => '2024-02-29T10:00', 'timeStep' => 900 ), true ),
    array( 'datetime-local', '2024-02-29T10:31', array( 'timeStep' => 900 ), false ),
    array( 'number', '1,3', array( 'min' => 0.3, 'step' => 0.5 ), true ),
    array( 'number', '1.4', array( 'min' => 0.3, 'step' => 0.5 ), false ),
    array( 'number', '1.5', array( 'integer' => true ), false ),
    array( 'number', '2', array( 'integer' => true ), true ),
    array( 'email', 'invalid', array( 'validate' => false ), true ),
    array( 'email', 'invalid', array(), false ),
    ) as [ $type, $value, $settings, $valid ]
) {
    $check(( array() === $input->validate($value, $element($type, $settings)) ) === $valid, 'Validation: ' . $type . ' / ' . $value);
}
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('Field type integration');
try {
    $fields = array(
        array( 'id' => 'custom', 'type' => 'custom-test', 'key' => 'custom', 'label' => 'Custom', 'required' => true ),
        array_merge($element('time', array( 'minDate' => '09:00', 'timeStep' => 900 )), array( 'required' => true )),
    );
    $saved = $service->save($id, 'Field type integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $check(array() === $saved['errors'], 'Save custom and temporal types');
    $html = swf_render_form($id);
    $check(str_contains($html, 'type="time"') && str_contains($html, 'min="09:00"') && str_contains($html, 'step="900"'), 'Native time attributes');
    add_filter('pre_wp_mail', '__return_true');
    $check('success' === swf_submit($id, array( 'custom' => 'custom value', 'test' => '09:30' ))->status, 'Custom type submission');
    $check('invalid' === swf_submit($id, array( 'custom' => 'custom value', 'test' => '09:31' ))->status, 'Server interval check');
    $fields[1] = array_merge($element('email', array( 'validate' => false )), array( 'required' => true ));
    $service->save($id, 'Field type integration', array( 'fields' => $fields ));
    $check('success' === swf_submit($id, array( 'custom' => 'custom value', 'test' => 'not-email' ))->status, 'Disabled format validation');
    $check('invalid' === swf_submit($id, array( 'custom' => 'custom value', 'test' => '' ))->status, 'Required remains enforced');
} finally {
    ( new \SWForms\WordPress\Forms\FormRepository() )->delete($id);
}
echo "PASS: $checks field type integration checks.\n";
