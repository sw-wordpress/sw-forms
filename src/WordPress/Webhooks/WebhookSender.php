<?php
/**
 * Performs one HTTP request of a webhook: SSRF check, body encoding, headers, signature.
 *
 * The target host is resolved once; all addresses must be public (or explicitly allowed) and the
 * connection is pinned to the checked address, so a DNS answer cannot change between check and
 * request. Redirects are not followed: the target of a redirect was never checked.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Webhook\NetworkGuard;
use SWForms\Core\Webhook\RetryPolicy;
use SWForms\Core\Webhook\Signature;
use SWForms\WordPress\Support\Settings;

final class WebhookSender {

	public const USER_AGENT_PRODUCT = 'SW-Forms';
	private const RESPONSE_EXCERPT  = 300;

	public function __construct( private readonly SecretStore $secrets = new SecretStore() ) {}

	/**
	 * @param array<string, mixed>                                                                                                             $webhook Normalized webhook settings.
	 * @param array<string, mixed>                                                                                                             $payload
	 * @param array{event: string, event_id: string, attempt: int, json?: string, secret?: string|null, header_values?: array<string, string>} $context
	 *        `json`: the stored JSON of the payload, sent verbatim (keeps `{}` vs `[]`);
	 *        `secret` / `header_values` override the stored secrets (test with unsaved values).
	 * @return array{status: int|null, success: bool, retryable: bool, message: string, duration_ms: int, body: string}
	 */
	public function send( int $form_id, array $webhook, array $payload, array $context ): array {
		$url    = (string) $webhook['url'];
		$parsed = NetworkGuard::parseUrl( $url );
		if ( NetworkGuard::OK !== $parsed['error'] ) {
			return self::failure( __( 'The webhook URL is invalid.', 'sw-forms' ) );
		}
		$ips   = self::resolve( $parsed['host'] );
		$guard = new NetworkGuard( Settings::all()['webhook_allowed_hosts'] );
		$error = $guard->check( $parsed['host'], $ips );
		/**
		 * Filters whether a webhook may call a URL. Allows internal targets that the SSRF protection
		 * blocks (the global setting "Allowed internal hosts" covers most cases).
		 *
		 * @since 0.2.0
		 *
		 * @param bool         $allowed Result of the SSRF check.
		 * @param string       $url     Target URL.
		 * @param list<string> $ips     Resolved addresses.
		 */
		if ( ! apply_filters( 'swf_webhook_url_allowed', NetworkGuard::OK === $error, $url, $ips ) ) {
			return self::failure(
				NetworkGuard::UNRESOLVED === $error
					/* translators: %s: host name */
					? sprintf( __( 'The host %s could not be resolved.', 'sw-forms' ), $parsed['host'] )
					/* translators: %s: host name */
					: sprintf( __( 'The host %s points to a private or local network address. Add it to the allowed internal hosts in the settings if this is intended.', 'sw-forms' ), $parsed['host'] ),
				NetworkGuard::UNRESOLVED === $error
			);
		}

		$body    = 'form' === $webhook['format']
			? http_build_query( $payload, '', '&' )
			: ( $context['json'] ?? (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$headers = array(
			'Content-Type'               => 'form' === $webhook['format'] ? 'application/x-www-form-urlencoded; charset=utf-8' : 'application/json; charset=utf-8',
			'User-Agent'                 => self::USER_AGENT_PRODUCT . '/' . SWF_VERSION . ' (+' . home_url( '/' ) . ')',
			'X-SWForms-Event'            => $context['event'],
			'X-SWForms-Event-Id'         => $context['event_id'],
			'X-SWForms-Delivery-Attempt' => (string) $context['attempt'],
		);
		foreach ( $webhook['headers'] as $header ) {
			$name  = (string) $header['name'];
			$value = (string) $header['value'];
			if ( ! empty( $header['secret'] ) ) {
				$value = $context['header_values'][ strtolower( $name ) ] ?? $this->secrets->header( $form_id, (string) $webhook['id'], $name );
				if ( null === $value || '' === $value ) {
					/* translators: %s: header name */
					return self::failure( sprintf( __( 'The secret value of the header %s is missing or cannot be decrypted. Please enter it again.', 'sw-forms' ), $name ), false );
				}
			}
			$headers[ $name ] = $value;
		}
		$secret = array_key_exists( 'secret', $context ) && null !== $context['secret'] ? $context['secret'] : $this->secrets->signing_secret( $form_id, (string) $webhook['id'] );
		if ( null === $secret && $this->secrets->has_signing_secret( $form_id, (string) $webhook['id'] ) ) {
			return self::failure( __( 'The signing secret cannot be decrypted (changed security keys?). Please enter it again.', 'sw-forms' ), false );
		}
		if ( null !== $secret && '' !== $secret ) {
			$headers[ Signature::HEADER ] = Signature::header( $secret, $body, time() );
		}

		/**
		 * Filters the arguments of a webhook request (`wp_remote_request()`).
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, mixed> $args    Request arguments (method, headers, body, timeout ...).
		 * @param array<string, mixed> $webhook Webhook settings.
		 * @param array<string, mixed> $payload Payload before encoding.
		 */
		$args = apply_filters(
			'swf_webhook_request_args',
			array(
				'method'              => (string) $webhook['method'],
				'headers'             => $headers,
				'body'                => $body,
				/**
				 * Filters the timeout of webhook requests in seconds.
				 *
				 * @since 0.2.0
				 *
				 * @param int $seconds Timeout.
				 */
				'timeout'             => max( 1, (int) apply_filters( 'swf_webhook_timeout', 15 ) ),
				'redirection'         => 0,
				'reject_unsafe_urls'  => false,
				'limit_response_size' => 65536,
				'user-agent'          => $headers['User-Agent'],
			),
			$webhook,
			$payload
		);

		$pin   = self::pin( $parsed['host'], $parsed['port'], $ips );
		$start = microtime( true );
		if ( null !== $pin ) {
			add_action( 'http_api_curl', $pin, 10, 3 );
		}
		$response = wp_remote_request( $url, is_array( $args ) ? $args : array() );
		if ( null !== $pin ) {
			remove_action( 'http_api_curl', $pin, 10 );
		}
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			// Network errors and timeouts are usually temporary.
			$result = self::failure( $response->get_error_message(), true );
			return array( 'duration_ms' => $duration ) + $result;
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$excerpt  = self::excerpt( (string) wp_remote_retrieve_body( $response ) );
		$success  = RetryPolicy::isSuccess( $status );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		/* translators: 1: HTTP status code, 2: duration in milliseconds */
		$message = sprintf( __( 'HTTP %1$d in %2$d ms', 'sw-forms' ), $status, $duration );
		if ( $status >= 300 && $status < 400 && '' !== $location ) {
			/* translators: %s: redirect target */
			$message .= ' · ' . sprintf( __( 'Redirect to %s was not followed. Please enter the final URL.', 'sw-forms' ), $location );
		} elseif ( '' !== $excerpt ) {
			$message .= ': ' . $excerpt;
		}
		return array(
			'status'      => $status,
			'success'     => $success,
			'retryable'   => ! $success && RetryPolicy::isRetryable( $status ),
			'message'     => $message,
			'duration_ms' => $duration,
			'body'        => $excerpt,
		);
	}

	/**
	 * @return list<string>
	 */
	private static function resolve( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = gethostbynamel( $host );
		$ips = is_array( $ips ) ? $ips : array();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() warns on lookup failures.
		$records = @dns_get_record( $host, DNS_AAAA );
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( isset( $record['ipv6'] ) ) {
				$ips[] = (string) $record['ipv6'];
			}
		}
		/**
		 * Filters the resolved addresses of a webhook host (tests, split horizon DNS).
		 *
		 * @since 0.2.0
		 *
		 * @param list<string> $ips  Addresses.
		 * @param string       $host Host name.
		 */
		$ips = apply_filters( 'swf_webhook_resolve', array_values( array_unique( $ips ) ), $host );
		return is_array( $ips ) ? array_values( array_filter( $ips, 'is_string' ) ) : array();
	}

	/**
	 * Callback for cURL that connects to the checked address (DNS rebinding protection).
	 *
	 * @param list<string> $ips
	 */
	private static function pin( string $host, int $port, array $ips ): ?\Closure {
		if ( array() === $ips || false !== filter_var( $host, FILTER_VALIDATE_IP ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return null;
		}
		$ip = $ips[0];
		foreach ( $ips as $candidate ) {
			if ( ! str_contains( $candidate, ':' ) ) {
				$ip = $candidate;
				break;
			}
		}
		$entry = $host . ':' . $port . ':' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip );
		return static function ( $handle ) use ( $entry ): void {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the checked address.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) );
		};
	}

	private static function excerpt( string $body ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) );
		return mb_strlen( $text ) > self::RESPONSE_EXCERPT ? mb_substr( $text, 0, self::RESPONSE_EXCERPT ) . '…' : $text;
	}

	/**
	 * @return array{status: null, success: false, retryable: bool, message: string, duration_ms: int, body: string}
	 */
	private static function failure( string $message, bool $retryable = false ): array {
		return array(
			'status'      => null,
			'success'     => false,
			'retryable'   => $retryable,
			'message'     => $message,
			'duration_ms' => 0,
			'body'        => '',
		);
	}
}
