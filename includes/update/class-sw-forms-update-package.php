<?php
/**
 * Validate the exact file handed to WordPress before any extraction.
 *
 * @package SWF
 */

declare( strict_types=1 );

/**
 * Internal verified update component.
 *
 * @since 0.5.0
 */
final class SWF_Update_Package {
	/**
	 * @param string              $file Downloaded ZIP.
	 * @param array<string,mixed> $package
	 * @return true|WP_Error
	 * @throws RuntimeException Caught and converted into WP_Error.
	 */
	public static function verify( string $file, array $package ): true|WP_Error {
		clearstatcache( true, $file );
		$hash = is_file( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( ! class_exists( 'ZipArchive' ) || ! is_file( $file ) || filesize( $file ) !== $package['bytes'] || ! is_string( $hash ) || ! hash_equals( $package['sha256'], $hash ) ) {
			return new WP_Error( 'invalid_update_file', __( 'ZIP size or SHA-256 does not match, or ZIP verification is unavailable.', 'sw-forms' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return new WP_Error( 'invalid_update_zip', __( 'Invalid ZIP archive.', 'sw-forms' ) );
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
			$count = $zip->numFiles;
			if ( $count < 1 || $count > 10000 ) {
				throw new RuntimeException( __( 'Too many ZIP entries.', 'sw-forms' ) );
			}
			$total = 0;
			$seen  = array();
			foreach ( range( 0, $count - 1 ) as $index ) {
				$stat = $zip->statIndex( $index );
				if ( false === $stat ) {
					throw new RuntimeException( __( 'ZIP entry is unreadable.', 'sw-forms' ) );
				}
				$name  = $stat['name'];
				$parts = explode( '/', rtrim( $name, '/' ) );
				$key   = strtolower( rtrim( $name, '/' ) );
				if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attributes ) ) {
					throw new RuntimeException( __( 'ZIP file type is unreadable.', 'sw-forms' ) );
				}
				$mode = ( $attributes >> 16 ) & 0170000;
				if ( $parts[0] !== $package['slug'] || preg_match( '/[\\\\:\x00-\x1f]/', $name ) || in_array( '..', $parts, true ) || in_array( '.', $parts, true ) || in_array( '', $parts, true ) || isset( $seen[ $key ] ) || ( 0 !== $mode && ! in_array( $mode, array( 0100000, 0040000 ), true ) ) || ! empty( $stat['encryption_method'] ) ) {
					throw new RuntimeException( __( 'Unsafe ZIP path or file type.', 'sw-forms' ) );
				}
				$seen[ $key ] = true;
				$total       += $stat['size'];
				if ( $total > 262144000 || $stat['size'] > max( 1048576, 200 * $stat['comp_size'] ) ) {
					throw new RuntimeException( __( 'Extraction limit exceeded.', 'sw-forms' ) );
				}
			}
			$main = $zip->getFromName( $package['slug'] . '/' . $package['main_file'], 16384 );
			if ( ! is_string( $main ) || ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*\S.+$/mi', $main ) ) {
				throw new RuntimeException( __( 'Plugin main file is missing.', 'sw-forms' ) );
			}
			foreach ( array(
				'Version'           => 'version',
				'Requires PHP'      => 'requires_php',
				'Requires at least' => 'requires_wp',
			) as $header => $field ) {
				if ( ! preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $main, $match ) || trim( $match[1], " \t\r\n*/" ) !== $package[ $field ] ) {
					/* translators: %s: plugin header name. */
					throw new RuntimeException( sprintf( __( 'Plugin header contradicts the manifest: %s', 'sw-forms' ), $header ) );
				}
			}
			return true;
		} catch ( Throwable $error ) {
			return new WP_Error( 'unsafe_update_zip', $error->getMessage() );
		} finally {
			$zip->close();
		}
	}
}
