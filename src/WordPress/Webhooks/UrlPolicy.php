<?php
/**
 * Validate every destination before delivery, even for the site's own hostname.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

final class UrlPolicy {

	/** @var \Closure(string): list<string> */
	private readonly \Closure $resolve;

	/** @param (\Closure(string): list<string>)|null $resolve DNS resolver, injectable for tests. */
	public function __construct( ?\Closure $resolve = null ) {
		$this->resolve = $resolve ?? static function ( string $host ): array {
			$addresses = gethostbynamel( $host ) ?: array();
			foreach ( dns_get_record( $host, DNS_AAAA ) ?: array() as $record ) {
				if ( isset( $record['ipv6'] ) ) {
					$addresses[] = $record['ipv6'];
				}
			}
			return array_values( array_unique( $addresses ) );
		};
	}
	/**
	 * Returns whether a narrowly scoped private-destination exception was granted.
	 *
	 * @throws \RuntimeException When the destination is not allowed.
	 */
	public function validate( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			throw new \RuntimeException( 'Invalid webhook destination.' );
		}
		/**
		 * Allows a trusted exact private destination; syntax restrictions still apply.
		 *
		 * @since 0.2.0
		 * @param bool $allow Default false.
		 * @param string $url Destination URL.
		 */
		$allow = (bool) apply_filters( 'swf_webhook_allow_private_url', false, $url );
		if ( $allow ) {
			return true;
		}
		if ( ! in_array( $parts['port'] ?? ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 ), array( 80, 443 ), true ) ) {
			throw new \RuntimeException( 'Webhook destination port is not allowed.' );
		}
		$host = trim( $parts['host'], '[]' );
		$ips  = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : ( $this->resolve )( $host );
		if ( array() === $ips ) {
			throw new \RuntimeException( 'Webhook destination could not be resolved.' );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::public_ip( $ip ) ) {
				throw new \RuntimeException( 'Private or reserved webhook destinations are blocked.' );
			}
		}
		return false;
	}

	public static function public_ip( string $ip ): bool {
		$bytes = inet_pton( $ip );
		if ( false === $bytes ) {
			return false;
		}
		if ( 16 === strlen( $bytes ) && substr( $bytes, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
			$ip = (string) inet_ntop( substr( $bytes, 12 ) );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		// PHP's flags do not cover multicast, CGNAT and all documentation blocks.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$p = array_map( 'intval', explode( '.', $ip ) );
			return $p[0] < 224 && ! ( 100 === $p[0] && $p[1] >= 64 && $p[1] <= 127 )
				&& ! ( 192 === $p[0] && 0 === $p[1] && in_array( $p[2], array( 0, 2 ), true ) ) && ! ( 192 === $p[0] && 88 === $p[1] && 99 === $p[2] )
				&& ! ( 198 === $p[0] && ( in_array( $p[1], array( 18, 19 ), true ) || ( 51 === $p[1] && 100 === $p[2] ) ) )
				&& ! ( 203 === $p[0] && 0 === $p[1] && 113 === $p[2] );
		}
		// Require global IPv6 unicast, excluding transition/documentation prefixes.
		return ( ord( $bytes[0] ) & 0xe0 ) === 0x20
			&& ! ( substr( $bytes, 0, 2 ) === "\x20\x01" && ( ord( $bytes[2] ) & 0xfe ) === 0 )
			&& substr( $bytes, 0, 4 ) !== "\x20\x01\x0d\xb8"
			&& ! str_starts_with( strtolower( $ip ), '2002:' ) && ! str_starts_with( strtolower( $ip ), '3fff:' );
	}
}
