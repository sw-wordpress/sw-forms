<?php
/**
 * Authenticated encryption for webhook signing secrets at rest.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

final class SecretVault {

	public static function encrypt( string $secret ): string {
		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, 'swf-webhook-v1' );
		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'Webhook secret encryption failed.' );
		}
		return 'v1:' . base64_encode( $nonce . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encrypted envelope.
	}

	public static function decrypt( string $encrypted ): string {
		$bytes = str_starts_with( $encrypted, 'v1:' ) ? base64_decode( substr( $encrypted, 3 ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypted envelope.
		if ( false === $bytes || strlen( $bytes ) < 29 ) {
			throw new \RuntimeException( 'Webhook secret cannot be decrypted.' );
		}
		$secret = openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ), 'swf-webhook-v1' );
		if ( false === $secret ) {
			throw new \RuntimeException( 'Webhook secret cannot be decrypted.' );
		}
		return $secret;
	}

	private static function key(): string {
		return hash( 'sha256', 'swf-webhook-v1:' . wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}
}
