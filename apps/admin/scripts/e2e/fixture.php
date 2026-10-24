<?php
/**
 * E2E fixture: `php fixture.php setup` creates a published test form and page (prints JSON),
 * `php fixture.php teardown <form> <page>` removes them. Never sends mail (no notifications).
 */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';

if ( 'teardown' === ( $argv[1] ?? '' ) ) {
	( new \SWForms\WordPress\Forms\FormRepository() )->delete( (int) $argv[2] );
	wp_delete_post( (int) $argv[3], true );
	echo "{}\n";
	exit;
}

$options = static fn ( array $pairs ): array => array_map( static fn ( $v, $l ) => array( 'label' => $l, 'value' => $v ), array_keys( $pairs ), $pairs );
$service = \SWForms\WordPress\Forms\FormService::create_default();
$form_id = $service->create( 'E2E' );
$result  = $service->save(
	$form_id,
	'E2E',
	array(
		'fields'   => array(
			array( 'id' => 'name', 'type' => 'input', 'key' => 'name', 'label' => 'Name', 'required' => true ),
			array( 'id' => 'mail', 'type' => 'input', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'settings' => array( 'inputType' => 'email' ) ),
			array(
				'id'      => 'cols',
				'type'    => 'columns',
				'columns' => array(
					array( 'id' => 'c1', 'width' => 6, 'children' => array( array( 'id' => 'kind', 'type' => 'radio', 'key' => 'kind', 'label' => 'Typ', 'settings' => array( 'options' => $options( array( 'private' => 'Privat', 'company' => 'Firma' ) ), 'other' => true ) ) ) ),
					array( 'id' => 'c2', 'width' => 6, 'children' => array( array( 'id' => 'company', 'type' => 'input', 'key' => 'company', 'label' => 'Firmenname', 'required' => true, 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'kind', 'op' => 'is', 'value' => 'company' ) ) ) ) ) ),
				),
			),
			array(
				'id'       => 'people',
				'type'     => 'group',
				'key'      => 'people',
				'label'    => 'Teilnehmende',
				'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 3, 'addLabel' => 'Person hinzufügen' ),
				'children' => array(
					array( 'id' => 'pname', 'type' => 'input', 'key' => 'name', 'label' => 'Name', 'required' => true ),
					array( 'id' => 'kid', 'type' => 'checkbox', 'key' => 'kid', 'label' => 'Kind', 'settings' => array( 'mode' => 'single' ) ),
					array( 'id' => 'age', 'type' => 'input', 'key' => 'age', 'label' => 'Alter', 'required' => true, 'settings' => array( 'inputType' => 'number', 'min' => 0, 'max' => 17 ), 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'kid', 'op' => 'notempty' ) ) ) ),
				),
			),
			array( 'id' => 'topics', 'type' => 'select', 'key' => 'topic', 'label' => 'Thema', 'settings' => array( 'options' => $options( array( 'a' => 'Allgemein', 'b' => 'Buchung' ) ) ) ),
			array( 'id' => 'msg', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht', 'settings' => array( 'maxLength' => 500 ) ),
			array( 'id' => 'ok', 'type' => 'checkbox', 'key' => 'consent', 'label' => 'Einverstanden', 'required' => true, 'settings' => array( 'mode' => 'single', 'text' => 'Ich stimme der <a href="#">Datenschutzerklärung</a> zu' ) ),
		),
		'settings' => array(
			'notifications' => array(),
			'confirmation'  => array( 'type' => 'message', 'message' => 'Danke, {field:name}!' ),
			'spam'          => array( 'honeypot' => true, 'minSeconds' => 2, 'rateLimit' => 0 ),
		),
	)
);
if ( array() !== $result['errors'] ) {
	fwrite( STDERR, wp_json_encode( $result['errors'] ) );
	exit( 1 );
}
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'SW Forms E2E',
		'post_content' => '[sw_form id="' . $form_id . '"]',
	)
);
echo wp_json_encode(
	array(
		'form' => $form_id,
		'page' => $page_id,
		'url'  => get_permalink( $page_id ),
	)
), "\n";
