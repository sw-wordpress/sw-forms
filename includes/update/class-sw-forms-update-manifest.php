<?php
/**
 * Signed, repository-bound release metadata. No WordPress state or HTTP.
 *
 * @package SWF
 */

declare( strict_types=1 );

/**
 * Internal verified update component.
 *
 * @since 0.5.0
 */
final class SWF_Update_Manifest {
	public const MAX_BYTES = 262144;

	/** @return array{repository:string,repository_id:int,main_slug:string,state_key:string,lock_key:string,allowed_slugs:list<string>} */
	public static function source(): array {
		return require __DIR__ . '/source.php';
	}

	/**
	 * @param string               $raw Exact signed bytes.
	 * @param string               $envelope Signature metadata.
	 * @param array<string,string> $keys
	 * @param array<string,mixed>  $release
	 * @return array<string,mixed>|WP_Error
	 * @throws RuntimeException Caught and converted into WP_Error.
	 */
	public static function verify( string $raw, string $envelope, array $keys, array $release ): array|WP_Error {
		try {
			if ( strlen( $raw ) > self::MAX_BYTES || strlen( $envelope ) > 1024 || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				throw new RuntimeException( __( 'Signature verification is unavailable or metadata is too large.', 'sw-forms' ) );
			}
			self::unique_keys( $envelope );
			$sig = json_decode( $envelope, true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $sig ) || count( $sig ) !== 2 || ! is_string( $sig['key_id'] ?? null ) || ! is_string( $sig['signature'] ?? null ) ) {
				throw new RuntimeException( __( 'Invalid signature envelope.', 'sw-forms' ) );
			}
			$key       = base64_decode( $keys[ $sig['key_id'] ] ?? '', true );
			$signature = base64_decode( $sig['signature'], true );
			if ( ! is_string( $key ) || strlen( $key ) !== 32 || ! is_string( $signature ) || strlen( $signature ) !== 64 || ! sodium_crypto_sign_verify_detached( $signature, $raw, $key ) ) {
				throw new RuntimeException( __( 'Unknown key or invalid release signature.', 'sw-forms' ) );
			}
			$data = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
			self::unique_keys( $raw );
			if ( ! is_array( $data ) || ( $data['schema'] ?? null ) !== 1 || ( $data['repository'] ?? '' ) !== self::source()['repository'] || ( $data['repository_id'] ?? null ) !== self::source()['repository_id'] || ! is_int( $data['release_id'] ?? null ) || $data['release_id'] <= 0 || ( $release['id'] ?? null ) !== $data['release_id'] || ( $data['tag'] ?? '' ) !== ( $release['tag_name'] ?? null ) || ( $release['draft'] ?? null ) !== false || ( $release['prerelease'] ?? null ) !== false ) {
				throw new RuntimeException( __( 'Release identity does not match the signature.', 'sw-forms' ) );
			}
			if ( ! preg_match( '/^[a-f0-9]{40}$/D', $data['commit'] ?? '' ) || ! preg_match( '/^v?\d+(?:\.\d+){2,3}(?:-\d{2})?$/D', $data['tag'] ) || ! is_int( $data['issued_at'] ?? null ) || $data['issued_at'] > time() + 300 || $data['issued_at'] <= 0 || ! is_array( $data['packages'] ?? null ) || ! array_is_list( $data['packages'] ) || count( $data['packages'] ) > 20 ) {
				throw new RuntimeException( __( 'Invalid manifest format.', 'sw-forms' ) );
			}
			$fields = array( 'schema', 'repository', 'repository_id', 'release_id', 'tag', 'commit', 'issued_at', 'packages' );
			if ( count( $data ) !== count( $fields ) || array_diff( array_keys( $data ), $fields ) ) {
				throw new RuntimeException( __( 'Unknown manifest fields.', 'sw-forms' ) );
			}
			$asset_ids   = array();
			$asset_names = array();
			foreach ( $release['assets'] ?? array() as $asset ) {
				if ( ! is_int( $asset['id'] ?? null ) || $asset['id'] <= 0 || ! is_string( $asset['name'] ?? null ) || isset( $asset_ids[ $asset['id'] ], $asset_names[ $asset['name'] ] ) || isset( $asset_ids[ $asset['id'] ] ) || isset( $asset_names[ $asset['name'] ] ) ) {
					throw new RuntimeException( __( 'Ambiguous release assets.', 'sw-forms' ) );
				}
				$asset_ids[ $asset['id'] ]     = true;
				$asset_names[ $asset['name'] ] = true;
			}
			$seen = array();
			foreach ( $data['packages'] as $package ) {
				$fields = array( 'slug', 'version', 'asset_id', 'filename', 'sha256', 'bytes', 'requires_php', 'requires_wp', 'main_file' );
				if ( ! is_array( $package ) || count( $package ) !== count( $fields ) || array_diff( array_keys( $package ), $fields ) ) {
					throw new RuntimeException( __( 'Unknown package fields.', 'sw-forms' ) );
				}
				$slug    = $package['slug'] ?? '';
				$version = $package['version'] ?? '';
				if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) || isset( $seen[ $slug ] ) || ! in_array( $slug, self::source()['allowed_slugs'], true ) || ! preg_match( '/^\d+(?:\.\d+){2,3}$/D', $version ) || ( $package['filename'] ?? '' ) !== "$slug-$version.zip" || ( $package['main_file'] ?? '' ) !== "$slug.php" || ! preg_match( '/^[a-f0-9]{64}$/D', $package['sha256'] ?? '' ) || ! is_int( $package['asset_id'] ?? null ) || $package['asset_id'] <= 0 || ! is_int( $package['bytes'] ?? null ) || $package['bytes'] < 1 || $package['bytes'] > 52428800 ) {
					throw new RuntimeException( __( 'Invalid or duplicate package entry.', 'sw-forms' ) );
				}
				foreach ( array( 'requires_php', 'requires_wp' ) as $field ) {
					if ( ! preg_match( '/^\d+(?:\.\d+){1,3}$/D', $package[ $field ] ?? '' ) ) {
						throw new RuntimeException( __( 'Minimum version is missing.', 'sw-forms' ) );
					}
				}
				$asset = array_values( array_filter( $release['assets'] ?? array(), static fn ( $asset ) => ( $asset['id'] ?? null ) === $package['asset_id'] && ( $asset['name'] ?? '' ) === $package['filename'] && ( $asset['size'] ?? null ) === $package['bytes'] ) );
				if ( count( $asset ) !== 1 ) {
					throw new RuntimeException( __( 'Release asset does not match the signed package.', 'sw-forms' ) );
				}
				$seen[ $slug ] = true;
			}
			$main        = array_values( array_filter( $data['packages'], static fn ( $p ) => self::source()['main_slug'] === $p['slug'] ) );
			$tag_version = preg_replace( '/-\d{2}$/', '', ltrim( $data['tag'], 'v' ) );
			if ( count( $main ) !== 1 || $tag_version !== $main[0]['version'] ) {
				throw new RuntimeException( __( 'Main plugin version does not match the release tag.', 'sw-forms' ) );
			}
			return $data;
		} catch ( Throwable $error ) {
			/* translators: %s: verification error details. */
			return new WP_Error( 'untrusted_release', sprintf( __( 'Release verification failed: %s', 'sw-forms' ), $error->getMessage() ) );
		}
	}

	/**
	 * Reject duplicate object keys before json_decode's last-key-wins interpretation is used.
	 *
	 * @throws RuntimeException For duplicate keys.
	 */
	private static function unique_keys( string $json ): void {
		preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $json, $matches );
		$stack = array();
		foreach ( $matches[0] as $index => $token ) {
			if ( '{' === $token || '[' === $token ) {
				$stack[] = array();
			} elseif ( '}' === $token || ']' === $token ) {
				array_pop( $stack );
			} elseif ( str_starts_with( $token, '"' ) && ( $matches[0][ $index + 1 ] ?? '' ) === ':' ) {
				$key = json_decode( $token, true, 2, JSON_THROW_ON_ERROR );
				$top = count( $stack ) - 1;
				if ( isset( $stack[ $top ][ $key ] ) ) {
					throw new RuntimeException( __( 'Duplicate JSON key.', 'sw-forms' ) );
				}
				$stack[ $top ][ $key ] = true;
			}
		}
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array<string,mixed>|null
	 */
	public static function package( array $manifest, string $slug ): ?array {
		foreach ( $manifest['packages'] as $package ) {
			if ( $package['slug'] === $slug ) {
				return $package;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @param array<string,mixed> $package
	 */
	public static function url( array $manifest, array $package ): string {
		return 'https://github.com/' . self::source()['repository'] . '/releases/download/' . rawurlencode( $manifest['tag'] ) . '/' . rawurlencode( $package['filename'] );
	}
}
