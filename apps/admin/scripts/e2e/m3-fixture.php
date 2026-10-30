<?php
/** M3 browser fixture: no notifications, no external CAPTCHA calls. */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';
$service = \SWForms\WordPress\Forms\FormService::create_default();
$command = $argv[1] ?? '';
if ( 'setup' === $command ) {
	$id = $service->create( 'M3 browser test' );
	$condition = array( 'match' => 'all', 'rules' => array( array( 'field' => 'toggle', 'op' => 'notempty' ) ) );
	$result = $service->save( $id, 'M3 browser test', array(
		'fields' => array(
			array( 'id' => 'name', 'type' => 'input', 'key' => 'name', 'label' => 'Name', 'required' => true ),
			array( 'id' => 'email', 'type' => 'input', 'key' => 'email', 'label' => 'Email', 'required' => true, 'settings' => array( 'inputType' => 'email' ) ),
			array( 'id' => 'toggle', 'type' => 'checkbox', 'key' => 'toggle', 'label' => 'Change state', 'settings' => array( 'mode' => 'single' ) ),
			array( 'id' => 'details', 'type' => 'input', 'key' => 'details', 'label' => 'Details', 'logic' => array( 'action' => 'require' ) + $condition ),
			array( 'id' => 'locked', 'type' => 'input', 'key' => 'locked', 'label' => 'Locked', 'settings' => array( 'default' => 'Original' ), 'logic' => array( 'action' => 'disable' ) + $condition ),
			array( 'id' => 'choice', 'type' => 'select', 'key' => 'choice', 'label' => 'Choice', 'settings' => array( 'searchable' => true, 'options' => array( array( 'value' => 'a', 'label' => 'Alpha' ), array( 'value' => 'b', 'label' => 'Beta' ) ) ) ),
		),
		'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => 'Saved {field:name}' ) ),
	) );
	if ( array() !== $result['errors'] ) { throw new RuntimeException( wp_json_encode( $result['errors'] ) ); }
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'M3 browser test', 'post_content' => '[sw_form id="' . $id . '"]' ) );
	echo wp_json_encode( array( 'id' => $id, 'page' => $page, 'url' => get_permalink( $page ) ) );
} elseif ( 'entry' === $command ) {
	$entries = ( new \SWForms\WordPress\Entries\EntryRepository() )->search( (int) $argv[2], 'inbox', '', 1, 20, 'desc' );
	echo wp_json_encode( $entries['items'][0] ?? null );
} elseif ( 'erase' === $command ) {
	$callbacks = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	for ( $page = 1; $page < 50; ++$page ) { $r = $callbacks['sw-forms']['callback']( $argv[2], $page ); if ( $r['done'] ) { break; } }
	echo wp_json_encode( array( 'gone' => null === ( new \SWForms\WordPress\Entries\EntryRepository() )->find( (int) $argv[3] ) ) );
} elseif ( 'cleanup' === $command ) {
	$service->repository()->delete( (int) $argv[2] ); wp_delete_post( (int) $argv[3], true ); echo '{}';
}
