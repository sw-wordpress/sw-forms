<?php
/** Disposable populated lists for palette drop positioning. */
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 7)) . '/wp-load.php';
$service = \SWForms\WordPress\Forms\FormService::create_default();
if ('teardown' === ($argv[1] ?? '')) { $service->repository()->delete((int) $argv[2]); echo '{}'; exit; }
if ('document' === ($argv[1] ?? '')) { echo wp_json_encode(swf_get_form((int) $argv[2])); exit; }
$id = $service->create('E2E palette positions');
$input = static fn($id) => ['id' => $id, 'type' => 'input', 'key' => $id, 'label' => $id];
$result = $service->save($id, 'E2E palette positions', ['fields' => [$input('a'), $input('b'), $input('c'), ['id' => 'group', 'type' => 'group', 'key' => 'group', 'label' => 'Group', 'children' => [$input('d'), $input('e')]]]]);
if ($result['errors'] !== []) throw new RuntimeException(wp_json_encode($result['errors']));
echo wp_json_encode(['form' => $id]);
