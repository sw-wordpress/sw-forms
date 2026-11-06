<?php
/**
 * Portable form files exclude credentials, entries and site-specific delivery activation.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms;

final class PortableDocument {
	/** @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	public static function clean( array $document ): array {
		$walk     = static function ( array $node ) use ( &$walk ): array {
			foreach ( $node as $key => $value ) {
				if ( is_string( $key ) && preg_match( '/^(secret|password|token|authorization|api.?key|credential)$|secretEncrypted|hasSecret|accessToken|refreshToken|clientSecret|privateKey/i', $key ) ) {
					unset( $node[ $key ] );
				} elseif ( is_array( $value ) ) {
					$node[ $key ] = $walk( $value );
				}
			}
			if ( 'password' === ( $node['inputType'] ?? '' ) ) {
				$node['default'] = '';
			}
			return $node;
		};
		$document = $walk( $document );
		foreach ( $document['settings']['webhooks'] ?? array() as $i => $webhook ) {
			$webhook['headers'] = array();
			$webhook['enabled'] = false;
			// A webhook URL can itself be a credential (for example an incoming webhook).
			$webhook['url'] = '';
			$document['settings']['webhooks'][ $i ] = $webhook;
		}
		return $document;
	}
}
