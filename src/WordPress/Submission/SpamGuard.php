<?php
/**
 * Spam and abuse protection without WordPress nonces (they break on cached pages):
 * honeypot, signed submission token (minimum fill-in time, single use) and a rate limit.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Submission;

use SWForms\Core\Security\SubmissionToken;
use SWForms\WordPress\Support\Settings;

final class SpamGuard {

	public const HONEYPOT_FIELD = 'swf_website';
	public const TOKEN_MAX_AGE  = DAY_IN_SECONDS;
	private const RATE_WINDOW   = 10 * MINUTE_IN_SECONDS;

	private SubmissionToken $tokens;

	public function __construct() {
		$this->tokens = new SubmissionToken( Settings::secret() );
	}

	public function issue_token( int $form_id ): string {
		return $this->tokens->issue( $form_id, time(), wp_generate_uuid4() );
	}

	/**
	 * @param array<string, mixed> $spam Form spam settings.
	 * @return array{status: string, token_id: string} status: ok, spam, invalid_token, expired, too_fast, duplicate, rate_limited
	 */
	public function check( int $form_id, array $spam, string $honeypot, string $token, string $ip ): array {
		if ( ! empty( $spam['honeypot'] ) && '' !== $honeypot ) {
			return array(
				'status'   => 'spam',
				'token_id' => '',
			);
		}
		$result = $this->tokens->verify( $token, $form_id, time(), (int) ( $spam['minSeconds'] ?? 0 ), self::TOKEN_MAX_AGE );
		$status = match ( $result['status'] ) {
			SubmissionToken::OK       => 'ok',
			SubmissionToken::EXPIRED  => 'expired',
			SubmissionToken::TOO_FAST => 'too_fast',
			default                   => 'invalid_token',
		};
		if ( 'ok' === $status && false !== get_transient( self::used_key( $result['id'] ) ) ) {
			$status = 'duplicate';
		}
		$limit = (int) ( $spam['rateLimit'] ?? 0 );
		if ( 'ok' === $status && $limit > 0 && (int) get_transient( self::rate_key( $form_id, $ip ) ) >= $limit ) {
			$status = 'rate_limited';
		}
		return array(
			'status'   => $status,
			'token_id' => $result['id'],
		);
	}

	/** Marks the token as used and counts the submission for the rate limit. */
	public function record_success( int $form_id, string $token_id, string $ip ): void {
		if ( '' !== $token_id ) {
			set_transient( self::used_key( $token_id ), 1, self::TOKEN_MAX_AGE );
		}
		$key = self::rate_key( $form_id, $ip );
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	private static function used_key( string $token_id ): string {
		return 'swf_tok_' . md5( $token_id );
	}

	private static function rate_key( int $form_id, string $ip ): string {
		return 'swf_rl_' . $form_id . '_' . substr( hash_hmac( 'sha256', $ip, Settings::secret() ), 0, 24 );
	}
}
