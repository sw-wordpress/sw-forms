<?php
/**
 * Submissions without JavaScript: the form posts to the page it is on, the result is rendered
 * in place (errors) or replaces the form (confirmation), redirects are followed.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\WordPress\Submission\SpamGuard;
use SWForms\WordPress\Submission\SubmissionResult;
use SWForms\WordPress\Submission\SubmissionService;

final class Postback {

	/** @var array<int, SubmissionResult> */
	private static array $results = array();

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	public static function result( int $form_id ): ?SubmissionResult {
		return self::$results[ $form_id ] ?? null;
	}

	/**
	 * Request data of a submission, shared with the REST endpoint.
	 *
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return array<string, mixed>
	 */
	public static function request_meta( array $post ): array {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the client IP used for rate limiting and the (hashed) IP of entries.
		 * Behind a trusted reverse proxy return the forwarded address here.
		 *
		 * @since 0.1.0
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'swf_client_ip', $ip );
		return array(
			'honeypot'   => is_scalar( $post[ SpamGuard::HONEYPOT_FIELD ] ?? null ) ? (string) $post[ SpamGuard::HONEYPOT_FIELD ] : '',
			'token'      => is_scalar( $post['swf_token'] ?? null ) ? (string) $post['swf_token'] : '',
			'ip'         => $ip,
			'user_id'    => get_current_user_id(),
			'source_url' => is_scalar( $post['swf_source'] ?? null ) ? (string) $post['swf_source'] : wp_get_referer(),
		);
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- public form; protected by the signed submission token (SpamGuard).
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method || ( ! isset( $_POST['swf_form_id'] ) && ! isset( $_GET['swf_form'] ) ) ) {
			return;
		}
		$post    = wp_unslash( $_POST );
		$form_id = absint( $post['swf_form_id'] ?? wp_unslash( $_GET['swf_form'] ?? 0 ) );
		$raw     = is_array( $post['swf'] ?? null ) ? $post['swf'] : array();
		$files   = $_FILES; // PHP owns the temporary paths; validation checks is_uploaded_file().
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( 0 === $form_id ) {
			return;
		}
		$meta          = self::request_meta( $post );
		$meta['files'] = $files;
		if ( '' === (string) $meta['source_url'] ) {
			$meta['source_url'] = home_url( add_query_arg( array() ) );
		}
		$result = SubmissionService::create_default()->submit( $form_id, $raw, $meta );
		if ( SubmissionResult::SUCCESS === $result->status && '' !== $result->redirect ) {
			wp_redirect( $result->redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target configured by an administrator, may be external.
			exit;
		}
		self::$results[ $form_id ] = $result;
	}
}
