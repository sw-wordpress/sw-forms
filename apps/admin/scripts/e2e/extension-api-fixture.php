<?php
/** Disposable fixture. Requires the example add-on to be active in the test WordPress. */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';

if ( 'cleanup' === ( $argv[1] ?? '' ) ) {
	( new \SWForms\WordPress\Forms\FormRepository() )->delete( (int) $argv[2] );
	wp_delete_post( (int) $argv[3], true );
	echo '{}';
	exit;
}
if ( 'entries' === ( $argv[1] ?? '' ) ) {
	$repo  = new \SWForms\WordPress\Entries\EntryRepository();
	$items = $repo->search( (int) $argv[2], 'inbox', '', 1, 10, 'asc' )['items'];
	echo wp_json_encode( array( 'items' => $items, 'logs' => $items ? $repo->logs( (int) $items[0]['id'] ) : array() ) );
	exit;
}
if ( ! \SWForms\WordPress\Fields\FieldRegistry::instance()->has( 'example-reference' ) ) {
	fwrite( STDERR, "Activate examples/sw-forms-example in the disposable test WordPress first.\n" );
	exit( 1 );
}
$service = \SWForms\WordPress\Forms\FormService::create_default();
$id      = $service->create( 'E2E Extension API' );
$result  = $service->save( $id, 'E2E Extension API', array(
	'fields'   => array(
		array( 'id' => 'ref', 'type' => 'example-reference', 'key' => 'reference', 'label' => 'Reference', 'required' => true ),
		array( 'id' => 'name', 'type' => 'input', 'key' => 'name', 'label' => 'Name' ),
	),
	'settings' => array( 'notifications' => array(), 'spam' => array( 'minSeconds' => 0 ), 'confirmation' => array( 'type' => 'message', 'message' => '{example_reference:reference}' ) ),
) );
if ( $result['errors'] ) {
	fwrite( STDERR, wp_json_encode( $result['errors'] ) );
	exit( 1 );
}
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E Extension API', 'post_content' => '[sw_form id="' . $id . '"]' ) );
echo wp_json_encode( array( 'form' => $id, 'page' => $page, 'url' => get_permalink( $page ) ) );
