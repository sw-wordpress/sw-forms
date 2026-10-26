<?php

/** Integration checks for sliders, rating, scale, color and tags. */

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
$fields = array();
foreach (array( 'range', 'rating', 'scale', 'color', 'tags' ) as $type) {
    $check($registry->has($type), 'Registered: ' . $type);
    $fields[] = array( 'id' => $type, 'type' => $type, 'key' => $type, 'label' => $type, 'required' => true );
}
$fields[0]['settings'] = array( 'min' => 10, 'max' => 20, 'step' => 0.5 );
$fields[4]['settings'] = array( 'minTags' => 1, 'maxTags' => 3, 'maxTagLength' => 20 );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create('Scalar fields integration');
try {
    $saved = $service->save($id, 'Scalar fields integration', array( 'fields' => $fields, 'settings' => array( 'notifications' => array() ) ));
    $check($saved['errors'] === array(), 'Save scalar types');
    $doc = swf_get_form($id);
    $check((float) $doc['fields'][0]['settings']['default'] === 10.0, 'Range default aligns with minimum');
    $check($registry->get('rating')->default_value($doc['fields'][1]) === '', 'Rating initially unanswered');
    $check($registry->get('color')->default_value($doc['fields'][3]) === '#000000', 'Color default');
    $html = swf_render_form($id);
    foreach (array( 'type="range"', 'type="color"', 'swf-score--rating', 'swf-score--scale', 'data-swf-tags', 'swf-range-value' ) as $markup) {
        $check(str_contains($html, $markup), 'Markup: ' . $markup);
    }
    $raw = array( 'range' => '10.5', 'rating' => '4', 'scale' => '8', 'color' => '#ABCDEF', 'tags' => "alpha,beta\nalpha" );
    add_filter('pre_wp_mail', '__return_true');
    $result = swf_submit($id, $raw);
    $check($result->status === 'success', 'Valid scalar submission');
    $entry = (new \SWForms\WordPress\Entries\EntryRepository())->find($result->entry_id);
    $check($entry['data'] === array( 'range' => '10.5', 'rating' => '4', 'scale' => '8', 'color' => '#abcdef', 'tags' => array( 'alpha', 'beta' ) ), 'Scalar and list storage');
    foreach (
        array(
        array( 'range', '10.2' ), array( 'range', '21' ), array( 'range', 'NaN' ),
        array( 'rating', '6' ), array( 'rating', '2.5' ), array( 'rating', '' ),
        array( 'scale', '11' ), array( 'scale', '-1' ),
        array( 'color', 'red' ), array( 'color', '#fff' ),
        array( 'tags', 'a,b,c,d' ), array( 'tags', str_repeat('x', 21) ), array( 'tags', '' ),
        ) as [$key, $value]
    ) {
        $check(swf_submit($id, array_replace($raw, array( $key => $value )))->status === 'invalid', 'Reject invalid ' . $key . ': ' . $value);
    }
    $range = $registry->get('range')->normalize_settings(array( 'min' => 2, 'max' => 1, 'step' => 0, 'default' => 999 ));
    $check($range['max'] >= $range['min'] && $range['step'] > 0, 'Normalize range bounds');
    $rating = $registry->get('rating')->normalize_settings(array( 'min' => 19, 'max' => 999 ));
    $check($rating['min'] === 1 && $rating['max'] === 10, 'Bound star count');
    $check($registry->get('rating')->validate('8', array()) !== array(), 'Unconfigured rating uses own default bounds');
    $tree = \SWForms\Core\Form\Tree::build($doc['fields'], $registry);
    $renderer = new \SWForms\WordPress\Frontend\Renderer($registry, $tree);
    $context = new \SWForms\WordPress\Frontend\RenderContext($renderer, $id, array( 'tags' => array( '<script>alert(1)</script>' ) ), array(), null);
    $tagHtml = $registry->get('tags')->render($doc['fields'][4], $context);
    $check(str_contains($tagHtml, '&lt;script&gt;') && !str_contains($tagHtml, '<script>'), 'Tags render as escaped plain text');
    $check($registry->get('tags')->format_text(array( 'a', 'b' ), $doc['fields'][4]) === 'a, b', 'Tag entry formatting');
} finally {
    (new \SWForms\WordPress\Forms\FormRepository())->delete($id);
}
echo "PASS: $checks scalar field integration checks.\n";
