<?php
/**
 * A PHP multipart upload, kept separate from user-provided form values.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Uploads;

final class IncomingFile {
	public function __construct(
		public readonly string $name,
		public readonly string $temporary,
		public readonly int $error,
	) {}

	/**
	 * Merge PHP's transposed $_FILES['swf'] structure into the corresponding request scopes.
	 * Only these objects can become uploads; strings, URLs and client file paths cannot.
	 *
	 * @param array<string, mixed> $raw
	 * @param array<string, mixed> $files
	 * @return array<string, mixed>
	 */
	public static function merge( array $raw, array $files ): array {
		$walk    = static function ( mixed $names, mixed $paths, mixed $errors ) use ( &$walk ): mixed {
			if ( is_array( $names ) ) {
				$out = array();
				foreach ( $names as $key => $name ) {
					$out[ $key ] = $walk( $name, is_array( $paths ) ? ( $paths[ $key ] ?? '' ) : '', is_array( $errors ) ? ( $errors[ $key ] ?? UPLOAD_ERR_PARTIAL ) : UPLOAD_ERR_PARTIAL );
				}
				return $out;
			}
			$name = is_string( $names ) ? self::clean_name( $names ) : '';
			return new self( $name, is_string( $paths ) ? $paths : '', is_int( $errors ) ? $errors : UPLOAD_ERR_PARTIAL );
		};
		$uploads = $walk( $files['name'] ?? array(), $files['tmp_name'] ?? array(), $files['error'] ?? array() );
		return array_replace_recursive( $raw, is_array( $uploads ) ? $uploads : array() );
	}

	/** PHP discards the whole POST body when post_max_size is exceeded. */
	public static function request_too_large(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		return $limit > 0 && absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ?? '0' ) ) > $limit;
	}

	public static function clean_name( string $name ): string {
		return trim( (string) preg_replace( '/[\x00-\x1f\x7f]/', '', wp_basename( str_replace( '\\', '/', $name ) ) ) );
	}

	/**
	 * @return list<self>
	 */
	public static function files( mixed $value ): array {
		$list = $value instanceof self ? array( $value ) : ( is_array( $value ) ? array_values( $value ) : array() );
		return array_values( array_filter( $list, static fn ( mixed $file ): bool => $file instanceof self && UPLOAD_ERR_NO_FILE !== $file->error ) );
	}
}
