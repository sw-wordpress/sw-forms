<?php
/** Fixture helper for the webhook builder browser check. */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';

$service = \SWForms\WordPress\Forms\FormService::create_default();
if ( 'setup' === ( $argv[1] ?? '' ) ) {
	$id = $service->create( 'E2E Webhooks ' . (string) $argv[2] );
	$service->save( $id, 'E2E Webhooks ' . (string) $argv[2], array(
		'fields' => array( array( 'id' => 'person', 'key' => 'name', 'type' => 'input', 'label' => 'Name' ) ),
		'settings' => array( 'notifications' => array() ),
	) );
	echo wp_json_encode( array( 'id' => $id ) );
} elseif ( 'inspect' === ( $argv[1] ?? '' ) ) {
	$doc = $service->repository()->published( (int) $argv[2] );
	$hook = $doc['settings']['webhooks'][0];
	echo wp_json_encode( array(
		'hasPlaintext' => str_contains( (string) wp_json_encode( $doc ), 'E2E known signing secret' ),
		'validSecret' => 'E2E known signing secret' === \SWForms\WordPress\Webhooks\SecretVault::decrypt( $hook['secretEncrypted'] ),
		'ciphertext' => $hook['secretEncrypted'],
	) );
} elseif ( 'cleanup' === ( $argv[1] ?? '' ) ) {
	$service->repository()->delete( (int) $argv[2] );
	echo '{}';
}
