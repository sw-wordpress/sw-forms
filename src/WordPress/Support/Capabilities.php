<?php
/**
 * Fine-grained capabilities (docs/rest-api.md).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

final class Capabilities {

	/** Create, edit and delete forms. */
	public const MANAGE_FORMS = 'swf_manage_forms';
	/** Read entries. */
	public const VIEW_ENTRIES = 'swf_view_entries';
	/** Change status of, and delete, entries. */
	public const MANAGE_ENTRIES = 'swf_manage_entries';
	/** Global plugin settings. */
	public const SETTINGS = 'swf_settings';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::MANAGE_FORMS, self::VIEW_ENTRIES, self::MANAGE_ENTRIES, self::SETTINGS );
	}

	/** Grants every capability to administrators. */
	public static function grant_defaults(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}
		foreach ( self::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}

	public static function revoke_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/** Whether the current user may open the admin app at all. */
	public static function can_access(): bool {
		foreach ( self::all() as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Capabilities of the current user (for the SPA; the server stays authoritative).
	 *
	 * @return list<string>
	 */
	public static function current_user_caps(): array {
		return array_values( array_filter( self::all(), static fn ( string $cap ): bool => current_user_can( $cap ) ) );
	}
}
