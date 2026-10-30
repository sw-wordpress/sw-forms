<?php
/**
 * Integration smoke test against a real WordPress (no PHPUnit WP test suite needed):
 * activates the plugin if necessary, builds a form, renders it, runs submissions through the
 * pipeline (invalid, valid, token reuse, honeypot), checks a schema error and cleans up.
 * Mails are intercepted. Usage: php tests/wp-smoke.php   (WP_ROOT overrides the WordPress path)
 */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 4 ) ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_plugin_active( 'sw-forms/sw-forms.php' ) ) {
	$r = activate_plugin( 'sw-forms/sw-forms.php' );
	echo 'activate: ', is_wp_error( $r ) ? $r->get_error_message() : 'ok', "\n";
}
wp_set_current_user( 1 );
global $wpdb;
echo 'tables: ', implode( ',', $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}swf_%'" ) ), "\n";
add_filter( 'pre_wp_mail', function ( $null, $atts ) { echo "MAIL to=", implode( ',', (array) $atts['to'] ), " subject=", $atts['subject'], "\n"; return true; }, 10, 2 );

$service = \SWForms\WordPress\Forms\FormService::create_default();
$id = $service->create( 'Smoke test' );
$doc = array(
	'fields' => array(
		array( 'id' => 'f_name', 'type' => 'input', 'key' => 'name', 'label' => 'Name', 'required' => true ),
		array( 'id' => 'f_mail', 'type' => 'input', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'settings' => array( 'inputType' => 'email' ) ),
		array( 'id' => 'cols', 'type' => 'columns', 'columns' => array(
			array( 'id' => 'c1', 'width' => 8, 'children' => array( array( 'id' => 'f_type', 'type' => 'radio', 'key' => 'type', 'label' => 'Typ', 'settings' => array( 'options' => array( array( 'label' => 'Privat', 'value' => 'private' ), array( 'label' => 'Firma', 'value' => 'company' ) ) ) ) ) ),
			array( 'id' => 'c2', 'width' => 4, 'children' => array( array( 'id' => 'f_company', 'type' => 'input', 'key' => 'company', 'label' => 'Firma', 'required' => true,
				'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'f_type', 'op' => 'is', 'value' => 'company' ) ) ) ) ) ),
		) ),
		array( 'id' => 'rep', 'type' => 'group', 'key' => 'people', 'label' => 'Personen', 'settings' => array( 'repeatable' => true, 'minRows' => 1, 'maxRows' => 3 ), 'children' => array(
			array( 'id' => 'p_name', 'type' => 'input', 'key' => 'name', 'label' => 'Name', 'required' => true ),
			array( 'id' => 'p_topics', 'type' => 'checkbox', 'key' => 'topics', 'label' => 'Themen', 'settings' => array( 'options' => array( array( 'label' => 'A', 'value' => 'a' ), array( 'label' => 'B', 'value' => 'b' ) ) ) ),
		) ),
		array( 'id' => 'f_ok', 'type' => 'checkbox', 'key' => 'consent', 'label' => 'Einverstanden', 'required' => true, 'settings' => array( 'mode' => 'single' ) ),
	),
	'settings' => array( 'confirmation' => array( 'type' => 'message', 'message' => 'Danke {field:name}!' ), 'spam' => array( 'minSeconds' => 0 ) ),
);
$result = $service->save( $id, 'Smoke test', $doc );
echo 'save errors: ', json_encode( $result['errors'] ), ' version: ', $result['version'], "\n";

$html = \SWForms\WordPress\Frontend\FormView::render( $id );
echo 'render bytes: ', strlen( $html ), ' company hidden: ', preg_match( '/data-swf-id="f_company"[^>]*hidden/', $html ) ? 'yes' : 'no', ' template: ', str_contains( $html, 'swf[rep][__INDEX__][p_name]' ) ? 'yes' : 'no', "\n";

$sub = \SWForms\WordPress\Submission\SubmissionService::create_default();
$guard = new \SWForms\WordPress\Submission\SpamGuard();
$meta = fn() => array( 'token' => $guard->issue_token( $id ), 'ip' => '10.0.0.' . wp_rand( 1, 250 ), 'honeypot' => '', 'wp_armour' => \SWForms\WordPress\Spam\WpArmour::request_data( function_exists( 'wpa_field_info' ) ? array( $GLOBALS['wpa_field_name'] => (string) wpa_field_info()['wpa_field_value'], 'alt_s' => '' ) : array() ) );

// 1. invalid: company required when visible, email invalid, consent missing, repeater name missing
$r = $sub->submit( $id, array( 'f_name' => 'Max', 'f_mail' => 'nope', 'f_type' => 'company', 'rep' => array( array( 'p_name' => '' ) ) ), $meta() );
echo '1 status=', $r->status, ' errors=', json_encode( $r->errors, JSON_UNESCAPED_UNICODE ), "\n";
// 2. valid: company hidden (private) though a stale value was posted
$r = $sub->submit( $id, array( 'f_name' => 'Max <b>', 'f_mail' => 'max@example.org', 'f_type' => 'private', 'f_company' => 'stale', 'rep' => array( array( 'p_name' => 'Anna', 'p_topics' => array( 'a', 'b' ) ), array( 'p_name' => 'Ben' ) ), 'f_ok' => '1' ), $meta() );
echo '2 status=', $r->status, ' msg=', $r->message, ' entry=', $r->entry_id, "\n";
$entry = ( new \SWForms\WordPress\Entries\EntryRepository() )->find( $r->entry_id );
echo '  data=', json_encode( $entry['data'], JSON_UNESCAPED_UNICODE ), "\n";
echo '  log=', json_encode( ( new \SWForms\WordPress\Entries\EntryRepository() )->logs( $r->entry_id ), JSON_UNESCAPED_UNICODE ), "\n";
// 3. token reuse
$m = $meta();
$sub->submit( $id, array( 'f_name' => 'A', 'f_mail' => 'a@example.org', 'f_ok' => '1', 'rep' => array( array( 'p_name' => 'x' ) ) ), $m );
$r = $sub->submit( $id, array( 'f_name' => 'A', 'f_mail' => 'a@example.org', 'f_ok' => '1', 'rep' => array( array( 'p_name' => 'x' ) ) ), $m );
echo '3 reuse status=', $r->status, ' code=', $r->code, "\n";
// 4. honeypot
$r = $sub->submit( $id, array(), array( 'honeypot' => 'spam' ) + $meta() );
echo '4 honeypot status=', $r->status, ' entry=', $r->entry_id, "\n";
// 5. schema error
$bad = $service->save( $id, 'x', array( 'fields' => array( array( 'id' => 'a', 'type' => 'input', 'key' => 'a', 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'a', 'op' => 'is', 'value' => '1' ) ) ) ) ) ) );
echo '5 schema errors=', json_encode( array_column( $bad['errors'], 'message' ), JSON_UNESCAPED_UNICODE ), "\n";
echo 'counts=', json_encode( ( new \SWForms\WordPress\Entries\EntryRepository() )->counts( $id ) ), "\n";
( new \SWForms\WordPress\Forms\FormRepository() )->delete( $id );
echo "cleanup done\n";
