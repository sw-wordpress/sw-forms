<?php
/**
 * E2E helper: prints auth cookies (1 hour) for an administrator of the local dev site,
 * `php admin-auth.php cleanup <form-title>` deletes forms created by the admin test.
 */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';

if ( 'entry' === ( $argv[1] ?? '' ) ) {
	// Submits one entry to the newest form whose title starts with $argv[2]; mails are swallowed.
	add_filter( 'pre_wp_mail', '__return_true' );
	$repo  = new \SWForms\WordPress\Forms\FormRepository();
	$forms = array_values( array_filter( $repo->all(), static fn ( $f ) => str_starts_with( $f['title'], (string) $argv[2] ) ) );
	$id    = (int) ( $forms[0]['id'] ?? 0 );
	$raw   = array();
	$walk  = static function ( array $elements ) use ( &$walk, &$raw ): void {
		foreach ( $elements as $el ) {
			if ( 'input' === $el['type'] ) {
				$raw[ $el['id'] ] = 'E2E Wert';
			} elseif ( 'radio' === $el['type'] ) {
				$raw[ $el['id'] ] = $el['settings']['options'][0]['value'] ?? '';
			}
			$walk( $el['children'] ?? array() );
			foreach ( $el['columns'] ?? array() as $column ) {
				$walk( $column['children'] );
			}
		}
	};
	$walk( $repo->published( $id )['fields'] ?? array() );
	$result = swf_submit( $id, $raw );
	echo wp_json_encode( array( 'status' => $result->status, 'entry' => $result->entry_id, 'errors' => $result->errors ) ), "\n";
	exit;
}

if ( 'cleanup' === ( $argv[1] ?? '' ) ) {
	$repo = new \SWForms\WordPress\Forms\FormRepository();
	foreach ( $repo->all() as $form ) {
		if ( str_starts_with( $form['title'], (string) $argv[2] ) ) {
			$repo->delete( (int) $form['id'] );
		}
	}
	echo "{}\n";
	exit;
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$user   = (int) ( $admins[0] ?? 0 );
$exp    = time() + HOUR_IN_SECONDS;
echo wp_json_encode(
	array(
		'cookies' => array(
			array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user, $exp, 'auth' ) ),
			array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user, $exp, 'logged_in' ) ),
		),
		'admin'   => admin_url( 'admin.php?page=sw-forms' ),
	)
), "\n";
