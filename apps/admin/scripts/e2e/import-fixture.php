<?php
/** Owned, read-only import sources and cleanup for the browser test. */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = 'localhost';
require ( getenv( 'WP_ROOT' ) ?: dirname( __DIR__, 7 ) ) . '/wp-load.php';

if ( 'cleanup' === ( $argv[1] ?? '' ) ) {
	$title = $argv[2] ?? '';
	if ( ! preg_match( '/^SWF Import E2E [a-f0-9]{12}$/D', $title ) ) {
		exit( 1 );
	}
	$repo = new \SWForms\WordPress\Forms\FormRepository();
	foreach ( $repo->all() as $form ) {
		if ( $title === $form['title'] ) {
			$repo->delete( (int) $form['id'] );
		}
	}
	foreach ( get_posts( array( 'post_type' => array( 'wpforms', 'wpcf7_contact_form' ), 'post_status' => 'any', 'numberposts' => -1 ) ) as $post ) {
		if ( $title === $post->post_title ) {
			wp_delete_post( $post->ID, true );
		}
	}
	echo '{}';
	exit;
}

if ( ! post_type_exists( 'wpforms' ) || ! class_exists( 'WPCF7_ContactForm' ) ) {
	fwrite( STDERR, "Activate WPForms and Contact Form 7 for this test.\n" );
	exit( 1 );
}
$title = 'SWF Import E2E ' . bin2hex( random_bytes( 6 ) );
$cf7   = wp_insert_post( array( 'post_type' => 'wpcf7_contact_form', 'post_title' => $title, 'post_status' => 'publish' ) );
update_post_meta( $cf7, '_form', '<label>Email [email* email]</label><label>Name [text* your-name]</label>[tel phone][text subject][textarea message][acceptance consent]I agree[/acceptance][file attachment][submit "Send"]' );
$wpf = wp_insert_post( array( 'post_type' => 'wpforms', 'post_title' => $title, 'post_status' => 'publish', 'post_content' => wp_slash( (string) wp_json_encode( array( 'fields' => array( array( 'type' => 'email', 'label' => 'Email', 'required' => '1' ), array( 'type' => 'payment-single', 'label' => 'Payment' ) ) ) ) ) ) );
echo wp_json_encode( array( 'title' => $title, 'sources' => array( 'contact-form-7' => $cf7, 'wpforms' => $wpf ) ) );
