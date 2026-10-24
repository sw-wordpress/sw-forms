<?php
/**
 * Global plugin settings (option `swf_settings`).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

final class Settings {

	public const OPTION = 'swf_settings';

	/**
	 * @return array{ip_mode: string, from_email: string, from_name: string, delete_data_on_uninstall: bool, load_css: bool}
	 */
	public static function defaults(): array {
		return array(
			'ip_mode'                  => 'hash',
			'from_email'               => '',
			'from_name'                => '',
			'delete_data_on_uninstall' => false,
			'load_css'                 => true,
		);
	}

	/**
	 * @return array{ip_mode: string, from_email: string, from_name: string, delete_data_on_uninstall: bool, load_css: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{ip_mode: string, from_email: string, from_name: string, delete_data_on_uninstall: bool, load_css: bool}
	 */
	public static function sanitize( array $input ): array {
		$out     = self::defaults();
		$ip_mode = $input['ip_mode'] ?? $out['ip_mode'];
		if ( in_array( $ip_mode, array( 'hash', 'none' ), true ) ) {
			$out['ip_mode'] = $ip_mode;
		}
		$email                           = sanitize_email( (string) ( $input['from_email'] ?? '' ) );
		$out['from_email']               = is_email( $email ) ? $email : '';
		$out['from_name']                = sanitize_text_field( (string) ( $input['from_name'] ?? '' ) );
		$out['delete_data_on_uninstall'] = (bool) ( $input['delete_data_on_uninstall'] ?? false );
		$out['load_css']                 = (bool) ( $input['load_css'] ?? true );
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function update( array $input ): void {
		update_option( self::OPTION, self::sanitize( array_merge( self::all(), $input ) ), false );
	}

	/** Site specific secret for submission tokens and IP hashing (never leaves the server). */
	public static function secret(): string {
		return hash_hmac( 'sha256', 'sw-forms', wp_salt( 'nonce' ) );
	}
}
