<?php
/**
 * Backend-only release checking, persistent daily throttling and verified downloads.
 *
 * @package SWF
 */

declare( strict_types=1 );
require_once __DIR__ . '/class-sw-forms-update-manifest.php';
require_once __DIR__ . '/class-sw-forms-update-transport.php';
require_once __DIR__ . '/class-sw-forms-update-package.php';
require_once __DIR__ . '/class-sw-forms-update-store.php';

/**
 * Internal verified update component.
 *
 * @since 0.5.0
 */
final class SWF_Update_Service {
	/**
	 * @return array<string,string>
	 */
	public static function keys(): array {
		$keys = defined( 'SWF_RELEASE_PUBLIC_KEYS' ) ? SWF_RELEASE_PUBLIC_KEYS : require __DIR__ . '/trusted-keys.php';
		return (array) apply_filters( 'swf_verified_release_public_keys', is_array( $keys ) ? $keys : array() );
	}

	public static function interactive(): bool {
		// admin-ajax.php does not set WP_NETWORK_ADMIN, even for network-admin requests.
		$network_context = ! is_multisite() || is_network_admin() || ( wp_doing_ajax() && current_user_can( 'manage_network_plugins' ) );
		return is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && $network_context && current_user_can( 'update_plugins' ) && wp_is_file_mod_allowed( 'plugin_updates' );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function check( bool $manual = false ): array|WP_Error {
		if ( ! self::interactive() ) {
			return new WP_Error( 'updates_forbidden', 'Update-Prüfung nur in der berechtigten Backend-Sitzung erlaubt.' );
		}
		if ( ! self::keys() || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new WP_Error( 'updates_not_configured', 'Öffentlicher Release-Schlüssel oder Sodium fehlt. GitHub-Updates sind gesperrt.' );
		}
		$lock = SWF_Update_Store::acquire();
		if ( false === $lock ) {
			return new WP_Error( 'updates_busy', 'Eine Update-Prüfung läuft bereits.' );
		}
		try {
			$state = SWF_Update_Store::read();
			if ( time() - ( $state['last_attempt'] ?? 0 ) < ( $manual ? 60 : DAY_IN_SECONDS ) ) {
				return new WP_Error( 'updates_throttled', 'Die letzte Update-Prüfung liegt noch innerhalb des Prüfintervalls.' );
			}
			$state['last_attempt'] = time();
			SWF_Update_Store::write( $state );
			$result = self::retrieve( $state );
			if ( ! SWF_Update_Store::owns( $lock ) ) {
				return new WP_Error( 'updates_lease_expired', 'Prüfung dauerte zu lange. Ergebnis wurde nicht übernommen.' );
			}
			if ( is_wp_error( $result ) ) {
				$state['error'] = $result->get_error_message();
				SWF_Update_Store::write( $state );
				return $result;
			}
			SWF_Update_Store::write( $result );
			return array( 'message' => 'Signierte Release-Informationen geprüft. Installation ausschließlich über die Plugin-Verwaltung.' );
		} catch ( Throwable $error ) {
			$state['error'] = 'Ungültige Release-Antwort.';
			if ( SWF_Update_Store::owns( $lock ) ) {
				SWF_Update_Store::write( $state );
			}
			return new WP_Error( 'invalid_release', $state['error'] );
		} finally {
			SWF_Update_Store::release( $lock );
		}
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>|WP_Error
	 */
	private static function retrieve( array $state ): array|WP_Error {
		$raw = SWF_Update_Transport::fetch( 'https://api.github.com/repos/' . SWF_Update_Manifest::source()['repository'] . '/releases/latest', 262144 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$release = json_decode( $raw, true );
		if ( ! is_array( $release ) || ! is_int( $release['id'] ?? null ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return new WP_Error( 'invalid_release', 'Keine gültige stabile Release-Antwort.' );
		}
		$files = array();
		foreach ( array(
			'sw-plugin-release.json'     => 262144,
			'sw-plugin-release.json.sig' => 1024,
		) as $name => $limit ) {
			$assets = array_values( array_filter( $release['assets'] ?? array(), static fn ( $a ) => ( $a['name'] ?? '' ) === $name ) );
			if ( count( $assets ) !== 1 || ! is_int( $assets[0]['id'] ?? null ) ) {
				return new WP_Error( 'missing_signature_asset', 'Release-Manifest oder Signatur fehlt.' );
			}
			// Construct from locally fixed identity, never trust an API-provided download URL.
			$url            = 'https://github.com/' . SWF_Update_Manifest::source()['repository'] . '/releases/download/' . rawurlencode( $release['tag_name'] ?? '' ) . '/' . $name;
			$files[ $name ] = SWF_Update_Transport::fetch( $url, $limit );
			if ( is_wp_error( $files[ $name ] ) ) {
				return $files[ $name ];
			}
		}
		$manifest = SWF_Update_Manifest::verify( $files['sw-plugin-release.json'], $files['sw-plugin-release.json.sig'], self::keys(), $release );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		foreach ( $manifest['packages'] as $package ) {
			$previous = $state['highest'][ $package['slug'] ] ?? '0';
			if ( version_compare( $package['version'], $previous, '<' ) ) {
				return new WP_Error( 'release_rollback', 'Eine bereits geprüfte neuere Version darf nicht zurückgenommen werden.' );
			}
			$state['highest'][ $package['slug'] ] = $package['version'];
		}
		$state['raw']          = $files['sw-plugin-release.json'];
		$state['signature']    = $files['sw-plugin-release.json.sig'];
		$state['release']      = $release;
		$state['manifest']     = $manifest;
		$state['last_success'] = time();
		$state['error']        = '';
		return $state;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function verified_snapshot() {
		$state = SWF_Update_Store::read();
		if ( empty( $state['last_success'] ) || time() - $state['last_success'] > 7 * DAY_IN_SECONDS ) {
			return new WP_Error( 'stale_update', 'Bitte vor der Installation die Updates erneut prüfen.' );
		}
		$manifest = SWF_Update_Manifest::verify( $state['raw'] ?? '', $state['signature'] ?? '', self::keys(), $state['release'] ?? array() );
		if ( ! is_wp_error( $manifest ) ) {
			foreach ( $manifest['packages'] as $package ) {
				if ( version_compare( $package['version'], $state['highest'][ $package['slug'] ] ?? '0', '<' ) ) {
					return new WP_Error( 'release_rollback', 'Veraltetes Release-Angebot.' );
				}
			}
		}
		return $manifest;
	}

	/**
	 * @param string              $url Expected package URL.
	 * @param array<string,mixed> $descriptor
	 * @return string|WP_Error
	 */
	public static function download( string $url, array $descriptor ): string|WP_Error {
		if ( ! self::interactive() ) {
			return new WP_Error( 'manual_update_required', 'GitHub-Installation nur in der berechtigten Backend-Sitzung erlaubt.' );
		}
		$manifest = self::verified_snapshot();
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$package = SWF_Update_Manifest::package( $manifest, $descriptor['slug'] );
		if ( null === $package || SWF_Update_Manifest::url( $manifest, $package ) !== $url || ! self::compatible( $package ) || ! version_compare( $package['version'], $descriptor['version'], '>' ) ) {
			return new WP_Error( 'untrusted_package', 'Paket, Version oder Systemvoraussetzungen stimmen nicht.' );
		}
		$file = wp_tempnam( 'swf-verified-update.zip' );
		if ( ! $file || ! chmod( $file, 0600 ) ) {
			if ( $file ) {
				wp_delete_file( $file );
			}
			return new WP_Error( 'update_tempfile', 'Temporäre Update-Datei nicht verfügbar.' );
		}
		$result = SWF_Update_Transport::fetch( $url, $package['bytes'], $file );
		if ( ! is_wp_error( $result ) ) {
			$result = SWF_Update_Package::verify( $file, $package );
		}
		if ( is_wp_error( $result ) ) {
			wp_delete_file( $file );
			return $result;
		}
		// Core consumes the archive during this request. Clean up even on an interrupted upgrade.
		register_shutdown_function(
			static function () use ( $file ): void {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		);
		return $file;
	}

	/**
	 * @param array<string,mixed> $package
	 */
	public static function compatible( array $package ): bool {
		return version_compare( PHP_VERSION, $package['requires_php'], '>=' ) && version_compare( get_bloginfo( 'version' ), $package['requires_wp'], '>=' );
	}
}
