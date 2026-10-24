<?php
/**
 * Merge tags allowed in default values: `{user:email}`, `{user:display_name}`, `{user:first_name}`,
 * `{user:last_name}`, `{user:login}` and `{get:parameter}`.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

use SWForms\Core\Template\MergeTags;

final class DynamicDefaults {

	public static function resolve( string $template ): string {
		if ( ! str_contains( $template, '{' ) ) {
			return $template;
		}
		return MergeTags::replace(
			$template,
			static function ( string $tag, string $arg ): ?string {
				if ( 'user' === $tag ) {
					return self::user( $arg );
				}
				if ( 'get' === $tag ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill from the URL.
					$value = isset( $_GET[ $arg ] ) && is_scalar( $_GET[ $arg ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $arg ] ) ) : '';
					return mb_substr( $value, 0, 500 );
				}
				return null;
			}
		);
	}

	private static function user( string $field ): string {
		$user = wp_get_current_user();
		if ( 0 === $user->ID ) {
			return '';
		}
		return match ( $field ) {
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'login'        => $user->user_login,
			default        => '',
		};
	}
}
