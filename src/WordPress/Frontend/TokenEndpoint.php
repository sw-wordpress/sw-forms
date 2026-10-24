<?php
/**
 * `admin-ajax.php?action=swf_token&form=3`: a fresh submission token (pages may be cached for
 * hours) and, for logged-in users, a REST nonce so the submission is attributed to them.
 * admin-ajax is used because it sees the login cookie without a nonce; it is never page cached.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\WordPress\Submission\SpamGuard;

final class TokenEndpoint {

	public const ACTION = 'swf_token';

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		nocache_headers();
		$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- issues an anti-spam token, no state change.
		if ( 0 === $form_id ) {
			wp_send_json_error( null, 400 );
		}
		wp_send_json_success(
			array(
				'token' => ( new SpamGuard() )->issue_token( $form_id ),
				'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			)
		);
	}
}
