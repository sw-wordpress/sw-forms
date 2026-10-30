<?php
/**
 * Safe HTTP boundary, also used by the authenticated test-send endpoint.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

final class Transport {

	public function __construct( private readonly UrlPolicy $policy = new UrlPolicy() ) {}

	/**
	 * @param array<string, mixed> $request Frozen URL and HTTP arguments.
	 * @return array{success: bool, code: int, duration: int, message: string}
	 */
	public function send( array $request ): array {
		$started     = microtime( true );
		$url         = (string) $request['url'];
		$allow       = false;
		$host_filter = static fn ( bool $external, string $host, string $checked ): bool => $checked === $url ? true : $external;
		$port_filter = static function ( array $ports, string $host, string $checked ) use ( $url ): array {
			return $checked === $url ? array_merge( $ports, array( (int) wp_parse_url( $url, PHP_URL_PORT ) ) ) : $ports;
		};
		try {
			$allow = $this->policy->validate( $url );
			if ( $allow ) {
				add_filter( 'http_request_host_is_external', $host_filter, 10, 3 );
				add_filter( 'http_allowed_safe_ports', $port_filter, 10, 3 );
			}
			unset( $request['url'] );
			// These limits cannot be relaxed by stored snapshots or request filters.
			$request['redirection']         = 0;
			$request['timeout']             = 15;
			$request['limit_response_size'] = 2048;
			$request['sslverify']           = true;
			$request['cookies']             = array();
			$response                       = wp_safe_remote_request( $url, $request );
			$code                           = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			// Do not log HTTP error strings: transport implementations can include credentials.
			$message = is_wp_error( $response ) ? 'Transport error: ' . $response->get_error_code() : wp_strip_all_tags( wp_remote_retrieve_body( $response ) );
			foreach ( $request['headers'] ?? array() as $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$message = str_replace( $value, '[redacted]', $message );
				}
			}
			return array(
				'success'  => $code >= 200 && $code < 300,
				'code'     => $code,
				'duration' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'message'  => mb_substr( $message, 0, 1000 ),
			);
		} catch ( \Throwable $error ) {
			return array(
				'success'  => false,
				'code'     => 0,
				'duration' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'message'  => __( 'Webhook destination blocked or request failed.', 'sw-forms' ),
			);
		} finally {
			if ( $allow ) {
				remove_filter( 'http_request_host_is_external', $host_filter );
				remove_filter( 'http_allowed_safe_ports', $port_filter );
			}
		}
	}
}
