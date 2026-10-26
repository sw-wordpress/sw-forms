<?php
/**
 * Symmetric encryption for stored secrets (webhook signing secrets, secret header values).
 *
 * Uses libsodium secretbox (XSalsa20-Poly1305). The key derives from the constant `SWF_ENCRYPTION_KEY`
 * if defined in wp-config.php, otherwise from the site's `AUTH_KEY` salt. If that key changes,
 * stored secrets can no longer be read and have to be entered again.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

final class Crypto {

	private const PREFIX = 'swf1:';

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext.
	}

	/** Null if the value is not readable (other key, manipulated). */
	public static function decrypt( string $stored ): ?string {
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? null : $plain;
	}

	private static function key(): string {
		$material = defined( 'SWF_ENCRYPTION_KEY' ) && is_string( SWF_ENCRYPTION_KEY ) && '' !== SWF_ENCRYPTION_KEY ? SWF_ENCRYPTION_KEY : wp_salt( 'auth' );
		return hash_hmac( 'sha256', 'sw-forms-secrets', $material, true );
	}
}
