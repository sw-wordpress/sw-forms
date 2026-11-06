<?php
/** Fixture commands for the real webhook delivery browser check. */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
$allowed_url = (string) getenv( 'SWF_TEST_WEBHOOK_URL' );
add_filter( 'swf_webhook_allow_private_url', static fn ( $allow, $url ) => $url === $allowed_url || $allow, 10, 2 );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$repo = new \SWForms\WordPress\Webhooks\DeliveryRepository();
$command = $argv[1] ?? '';
if ( 'setup' === $command ) {
	$id = $service->create( 'E2E Webhook delivery ' . time() );
	$service->save( $id, 'E2E Webhook delivery ' . time(), array(
		'fields' => array( array( 'id' => 'name', 'type' => 'input', 'key' => 'name', 'label' => 'Name' ) ),
		'settings' => array( 'notifications' => array(), 'webhooks' => array( array(
			'id' => 'echo', 'name' => 'Local echo', 'enabled' => true, 'url' => $allowed_url,
			'method' => 'POST', 'format' => 'json', 'secret' => 'E2E known signing secret',
		) ) ),
	) );
	echo wp_json_encode( array( 'id' => $id ) );
} elseif ( 'submit' === $command ) {
	$result = swf_submit( (int) $argv[2], array( 'name' => 'Browser delivery example' ) );
	$rows = $repo->for_entry( $result->entry_id );
	echo wp_json_encode( array( 'entry' => $result->entry_id, 'delivery' => $rows[0]['id'] ) );
} elseif ( 'run' === $command ) {
	$id = (int) $argv[2];
	global $wpdb;
	$wpdb->update( \SWForms\WordPress\Database\Schema::table( 'webhook_deliveries' ), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
	( new \SWForms\WordPress\Webhooks\DeliveryService() )->deliver( $id );
	$row = $repo->find( $id );
	echo wp_json_encode( array( 'status' => $row['status'], 'attempts' => (int) $row['attempts'] ) );
} elseif ( 'cleanup' === $command ) {
	$service->repository()->delete( (int) $argv[2] );
	echo '{}';
}
