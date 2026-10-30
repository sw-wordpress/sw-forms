<?php
/**
 * Moves validated, visible uploads only; rolls back partial writes and owns entry cleanup.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Uploads;

use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Visibility;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\FileField;

final class UploadStorage {
	/** @var list<string> Basenames owned by this submission. */
	private array $created = array();

	/** @return list<string> */
	public function manifest(): array {
		return $this->created;
	}

	public function rollback(): void {
		self::delete( $this->created );
		$this->created = array();
	}

	/**
	 * @param list<string> $names Only generated basenames, never paths from entry data or URLs.
	 */
	public static function delete( array $names ): void {
		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/swforms';
		foreach ( $names as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^swf-[a-f0-9]{32}\.(pdf|txt|csv|jpe?g|png|gif|webp|zip|webm|ogg|m4a|mp3|wav|mp4)$/D', $name ) ) {
				wp_delete_file_from_directory( $dir . '/' . $name, $dir );
			}
		}
	}

	/** Resolve only server-owned generated basenames for local mail attachments.
	 *
	 * @since 0.5.0
	 * @param list<string> $names Upload manifest from a stored entry.
	 * @return list<string>
	 */
	public static function attachments( array $names ): array {
		$dir = wp_upload_dir()['basedir'] . '/swforms';
		$out = array();
		foreach ( $names as $name ) {
			if ( is_string( $name ) && preg_match( '/^swf-[a-f0-9]{32}\.(pdf|txt|csv|jpe?g|png|gif|webp|zip|webm|ogg|m4a|mp3|wav|mp4)$/D', $name ) && is_file( $dir . '/' . $name ) && ! is_link( $dir . '/' . $name ) ) {
				$out[] = $dir . '/' . $name;
			}
		}
		return $out;
	}

	/**
	 * Returns values with file name lists replaced by stored descriptors, leaving condition values intact.
	 *
	 * @param array<string, mixed> $values
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 * @throws \RuntimeException When any move fails; the caller rolls back the batch.
	 */
	public function store( Tree $tree, FieldRegistry $registry, array $values, array $raw, Visibility $visibility ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$walk = function ( array $ids, array $scope, array $input, string $prefix ) use ( &$walk, $tree, $registry, $visibility ): array {
			foreach ( $ids as $id ) {
				if ( ! $visibility->isActive( $id, $prefix ) ) {
					continue;
				}
				if ( $tree->isRepeater( $id ) ) {
					$raw_rows = array_values( is_array( $input[ $id ] ?? null ) ? $input[ $id ] : array() );
					foreach ( $scope[ $id ] ?? array() as $index => $row ) {
						$scope[ $id ][ $index ] = $walk( $tree->childIds( $id ), $row, is_array( $raw_rows[ $index ] ?? null ) ? $raw_rows[ $index ] : array(), $prefix . $id . '[' . $index . '].' );
					}
				} elseif ( $tree->isLeafData( $id ) ) {
					$type = $registry->get( $tree->type( $id ) );
					if ( $type instanceof FileField ) {
						$scope[ $id ] = array();
						foreach ( IncomingFile::files( $input[ $id ] ?? null ) as $file ) {
							$scope[ $id ][] = $this->move( $file, $type, $tree->node( $id ) );
						}
					}
				} else {
					$scope = $walk( $tree->childIds( $id ), $scope, $input, $prefix );
				}
			}
			return $scope;
		};
		return $walk( $tree->rootIds(), $values, $raw, '' );
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array{name: string, url: string, size: int, type: string}
	 * @throws \RuntimeException When an upload cannot be moved.
	 */
	private function move( IncomingFile $file, FileField $type, array $element ): array {
		$ext  = strtolower( pathinfo( $file->name, PATHINFO_EXTENSION ) );
		$name = 'swf-' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		// Scope this directory override to this one move, including when another upload filter throws.
		$directory = static function ( array $dir ): array {
			$dir['subdir'] = '/swforms';
			$dir['path']   = $dir['basedir'] . $dir['subdir'];
			$dir['url']    = $dir['baseurl'] . $dir['subdir'];
			return $dir;
		};
		add_filter( 'upload_dir', $directory );
		try {
			$dir   = wp_upload_dir();
			$index = $dir['path'] . '/index.html';
			if ( ! empty( $dir['error'] ) || ( ! file_exists( $index ) && false === file_put_contents( $index, '' ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- empty generated index prevents directory listings on ordinary web servers.
				throw new \RuntimeException( 'Upload directory could not be prepared.' );
			}
			$upload = array(
				'name'     => $name,
				'tmp_name' => $file->temporary,
				'error'    => $file->error,
				'size'     => filesize( $file->temporary ),
				'type'     => '',
			);
			$result = wp_handle_upload(
				$upload,
				array(
					'test_form' => false,
					'mimes'     => $type->mimes( $element ),
				)
			);
		} finally {
			remove_filter( 'upload_dir', $directory );
		}
		// Record a moved file before checking metadata, so malformed filter results also roll back.
		if ( ! empty( $result['file'] ) && is_file( $result['file'] ) ) {
			$this->created[] = wp_basename( $result['file'] );
		}
		if ( isset( $result['error'] ) || empty( $result['file'] ) || ! is_file( $result['file'] ) || empty( $result['url'] ) ) {
			throw new \RuntimeException( 'Upload could not be stored.' );
		}
		return array(
			'name' => $file->name,
			'url'  => esc_url_raw( $result['url'] ),
			'size' => (int) filesize( $result['file'] ),
			'type' => (string) $result['type'],
		);
	}
}
