<?php
/**
 * Optional WP Armour integration (free and Extended).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Spam;

final class WpArmour {

	public static function available(): bool {
		return function_exists( 'wpa_check_is_spam' );
	}

	/**
	 * Keep submission tokens and unrelated request metadata out of Extended's logs.
	 * Preserve missing fields: WP Armour must distinguish absent from empty fields.
	 *
	 * @param array<string, mixed> $post Unslashed request body.
	 * @return array<string, mixed>
	 */
	public static function request_data( array $post ): array {
		$data = array( 'swf' => is_array( $post['swf'] ?? null ) ? $post['swf'] : array() );
		$name = $GLOBALS['wpa_field_name'] ?? null;
		foreach ( array( $name, 'alt_s' ) as $field ) {
			if ( is_string( $field ) && '' !== $field && array_key_exists( $field, $post ) ) {
				$data[ $field ] = $post[ $field ];
			}
		}
		return $data;
	}

	/**
	 * @param array<string, mixed> $data Submitted protection fields and form values.
	 */
	public static function is_spam( array $data ): bool {
		if ( ! self::available() ) {
			return false;
		}
		$name = $GLOBALS['wpa_field_name'] ?? null;
		// Reject malformed fields before invoking the third-party checker.
		$malformed = ! is_string( $name ) || '' === $name || ! is_string( $data[ $name ] ?? null ) || ! is_string( $data['alt_s'] ?? null );
		if ( $malformed || wpa_check_is_spam( $data ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Armour integration hook.
			do_action( 'wpa_handle_spammers', 'sw_forms', $data );
			return true;
		}
		return false;
	}
}
