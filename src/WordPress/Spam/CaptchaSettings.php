<?php
/**
 * CAPTCHA provider support.
 *
 * @package SWForms
 */

declare(strict_types=1);
namespace SWForms\WordPress\Spam;

use SWForms\WordPress\Webhooks\SecretVault;

final class CaptchaSettings {
	public const OPTION = 'swf_captcha';

	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @return array<string, array<string, mixed>> */
	public static function public_settings(): array {
		$out = array();
		foreach ( array( 'turnstile', 'hcaptcha' ) as $slug ) {
			$item         = self::all()[ $slug ] ?? array();
			$out[ $slug ] = array(
				'siteKey'   => $item['siteKey'] ?? '',
				'hasSecret' => ! empty( $item['secretEncrypted'] ),
			);
		}
		return $out;
	}

	/** @param array<string, mixed> $input */
	public static function update( array $input ): void {
		$out = self::all();
		foreach ( array( 'turnstile', 'hcaptcha' ) as $slug ) {
			if ( ! is_array( $input[ $slug ] ?? null ) ) {
				continue; }
			$item                    = $input[ $slug ];
			$out[ $slug ]['siteKey'] = is_string( $item['siteKey'] ?? null ) ? mb_substr( sanitize_text_field( $item['siteKey'] ), 0, 200 ) : ( $out[ $slug ]['siteKey'] ?? '' );
			if ( is_string( $item['secret'] ?? null ) && '' !== $item['secret'] ) {
				$out[ $slug ]['secretEncrypted'] = SecretVault::encrypt( mb_substr( $item['secret'], 0, 1000 ) );
			}
		}
		update_option( self::OPTION, $out, false );
	}
}
