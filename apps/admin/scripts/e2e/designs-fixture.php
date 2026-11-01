<?php
/**
 * E2E fixture for the design variants.
 * `php designs-fixture.php setup`: admin cookies, a design gallery URL valid for them and a
 * published "personal details" form (animated-float, prefilled title) embedded on a page.
 * `php designs-fixture.php entries <form>` prints the stored entry data,
 * `php designs-fixture.php teardown <form> <page>` removes everything.
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

if ( 'entries' === ( $argv[1] ?? '' ) ) {
	global $wpdb;
	$table = $wpdb->prefix . 'swf_entries';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture.
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT data FROM {$table} WHERE form_id = %d ORDER BY id", (int) $argv[2] ) );
	echo wp_json_encode( array_map( static fn ( $row ) => json_decode( (string) $row, true ), $rows ) ), "\n";
	exit;
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$user   = (int) ( $admins[0] ?? 0 );
$exp    = time() + HOUR_IN_SECONDS;
$auth   = wp_generate_auth_cookie( $user, $exp, 'auth' );
$login  = wp_generate_auth_cookie( $user, $exp, 'logged_in' );
// Nonces are bound to the session of the logged-in cookie the browser will send.
$_COOKIE[ LOGGED_IN_COOKIE ] = $login;
wp_set_current_user( $user );

$service = \SWForms\WordPress\Forms\FormService::create_default();
$form_id = $service->create( 'E2E designs' );
$input   = static fn ( string $id, string $label, bool $required, array $settings = array() ): array => array(
	'id'       => $id,
	'type'     => 'input',
	'key'      => $id,
	'label'    => $label,
	'required' => $required,
	'settings' => $settings,
);
$result  = $service->save(
	$form_id,
	'E2E designs',
	array(
		'fields'   => array(
			array(
				'id'       => 'salutation',
				'type'     => 'select',
				'key'      => 'salutation',
				'label'    => 'Anrede',
				'settings' => array(
					'options' => array(
						array( 'value' => 'ms', 'label' => 'Frau' ),
						array( 'value' => 'mr', 'label' => 'Herr' ),
					),
				),
			),
			$input( 'first_name', 'Vorname', true, array( 'autocomplete' => 'given-name' ) ),
			$input( 'last_name', 'Nachname', true, array( 'autocomplete' => 'family-name' ) ),
			$input( 'title', 'Titel', false, array( 'default' => 'Dr.' ) ),
			array(
				'id'    => 'note',
				'type'  => 'textarea',
				'key'   => 'note',
				'label' => 'Nachricht',
			),
		),
		'settings' => array(
			'submitLabel'   => 'Weiter',
			'notifications' => array(),
			'confirmation'  => array(
				'type'    => 'message',
				'message' => 'Danke, {field:first_name}!',
			),
			'spam'          => array(
				'honeypot'   => true,
				'minSeconds' => 0,
				'rateLimit'  => 0,
			),
			'design'        => array( 'variant' => 'animated-float' ),
		),
	)
);
if ( array() !== $result['errors'] ) {
	fwrite( STDERR, (string) wp_json_encode( $result['errors'] ) );
	exit( 1 );
}
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'SW Forms E2E designs',
		'post_content' => '[sw_form id="' . $form_id . '"]',
	)
);
echo wp_json_encode(
	array(
		'form'    => $form_id,
		'page'    => $page_id,
		'url'     => get_permalink( $page_id ),
		'gallery' => \SWForms\WordPress\Frontend\DesignGallery::url(),
		'cookies' => array(
			array(
				'name'  => AUTH_COOKIE,
				'value' => $auth,
			),
			array(
				'name'  => LOGGED_IN_COOKIE,
				'value' => $login,
			),
		),
	)
), "\n";
