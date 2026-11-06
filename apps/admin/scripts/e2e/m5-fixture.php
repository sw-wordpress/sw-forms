<?php
/** M5 fixture: owned forms/pages only; no notification or webhook side effects. */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';
$repo = new \SWForms\WordPress\Forms\FormRepository();
if ( 'cleanup' === ( $argv[1] ?? '' ) ) {
	foreach ( array_slice( $argv, 2 ) as $id ) { $repo->delete( (int) $id ); }
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 's' => 'SWF M5 E2E', 'numberposts' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); }
	echo '{}'; exit;
}
if ( 'entries' === ( $argv[1] ?? '' ) ) {
	echo wp_json_encode( ( new \SWForms\WordPress\Entries\EntryRepository() )->search( (int) $argv[2], 'inbox', '', 1, 10, 'asc' ) ); exit;
}
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create( 'SWF M5 E2E' );
$result = $service->save( $id, 'SWF M5 E2E', array( 'fields' => array(
	array( 'id' => 'quantity', 'type' => 'input', 'key' => 'quantity', 'label' => 'Quantity', 'required' => true, 'settings' => array( 'inputType' => 'number' ) ),
	array( 'id' => 'price', 'type' => 'input', 'key' => 'price', 'label' => 'Price', 'settings' => array( 'inputType' => 'number' ) ),
	array( 'id' => 'break', 'type' => 'page-break', 'label' => 'Details' ),
	array( 'id' => 'name', 'type' => 'input', 'key' => 'name', 'label' => 'Name', 'required' => true ),
	array( 'id' => 'total', 'type' => 'calculation', 'key' => 'total', 'label' => 'Total', 'settings' => array( 'operation' => 'product', 'sources' => array( array( 'field' => 'quantity' ), array( 'field' => 'price' ) ) ) ),
	array( 'id' => 'info', 'type' => 'html', 'settings' => array( 'content' => '<strong>Information</strong>' ) ),
), 'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0, 'rateLimit' => 0 ), 'confirmation' => array( 'message' => 'Total: {field:total}' ) ) ) );
if ( $result['errors'] ) { fwrite( STDERR, wp_json_encode( $result['errors'] ) ); exit( 1 ); }
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SWF M5 E2E', 'post_content' => '[sw_form id="' . $id . '"]' ) );
$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'SWF M5 E2E Gutenberg' ) );
echo wp_json_encode( array( 'form' => $id, 'url' => get_permalink( $page ), 'editPost' => admin_url( 'post.php?post=' . $post . '&action=edit' ) ) );
