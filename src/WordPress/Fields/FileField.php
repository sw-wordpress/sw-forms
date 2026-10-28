<?php
/**
 * Single/multiple multipart uploads with server-enforced limits.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;
use SWForms\WordPress\Uploads\IncomingFile;

class FileField extends AbstractFieldType {
	/** Restricted to inert formats; also intersected with WordPress's allowed MIME types. */
	private const MIMES = array(
		'pdf'  => 'application/pdf',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'zip'  => 'application/zip',
	);

	public function type(): string {
		return 'file';
	}

	public function label(): string {
		return __( 'File upload', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_upload_file';
	}

	public function category(): string {
		return 'media';
	}

	public function value_kind( array $element ): string {
		return 'list';
	}

	public function default_value( array $element ): mixed {
		return array();
	}


	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'multiple',
				'type'    => 'toggle',
				'tab'     => 'general',
				'label'   => __( 'Allow multiple files', 'sw-forms' ),
				'default' => false,
				'help'    => __( 'Uploads require entry storage. Files have public, unguessable URLs until the entry is permanently deleted.', 'sw-forms' ),
			),
			array(
				'name'    => 'extensions',
				'type'    => 'text',
				'tab'     => 'validation',
				'label'   => __( 'Allowed file extensions', 'sw-forms' ),
				'help'    => __( 'Comma-separated: pdf, txt, csv, jpg, jpeg, png, gif, webp, zip. WordPress restrictions also apply. Empty means no uploads allowed.', 'sw-forms' ),
				'default' => 'pdf,jpg,jpeg,png,txt',
			),
			array(
				'name'    => 'maxSize',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Maximum size per file (MB)', 'sw-forms' ),
				'min'     => 1,
				'max'     => 100,
				'integer' => true,
				'default' => 10,
			),
			array(
				'name'    => 'maxFiles',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Maximum files', 'sw-forms' ),
				'min'     => 1,
				'max'     => 20,
				'integer' => true,
				'default' => 5,
				'showIf'  => array(
					'setting' => 'multiple',
					'equals'  => array( true ),
				),
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings               = parent::normalize_settings( $settings );
		$parts                  = preg_split( '/[\s,;]+/', strtolower( (string) $settings['extensions'] ) ) ?: array();
		$parts                  = array_map( static fn ( string $part ): string => ltrim( $part, '.' ), $parts );
		$settings['extensions'] = implode( ',', array_values( array_unique( array_intersect( $parts, array_keys( self::MIMES ) ) ) ) );
		return $settings;
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array<string, string>
	 */
	public function mimes( array $element ): array {
		$out = array();
		foreach ( explode( ',', (string) $this->setting( $element, 'extensions', 'pdf,jpg,jpeg,png,txt' ) ) as $ext ) {
			$wp = wp_check_filetype( 'file.' . $ext, get_allowed_mime_types() );
			if ( isset( self::MIMES[ $ext ] ) && self::MIMES[ $ext ] === $wp['type'] ) {
				$out[ $ext ] = self::MIMES[ $ext ];
			}
		}
		return $out;
	}

	/** @param array<string, mixed> $element */
	public function max_files( array $element ): int {
		return $this->setting( $element, 'multiple', false ) ? (int) $this->setting( $element, 'maxFiles', 5 ) : 1;
	}

	/** @param array<string, mixed> $element */
	public function max_size( array $element ): int {
		return min( (int) $this->setting( $element, 'maxSize', 10 ) * MB_IN_BYTES, wp_max_upload_size() );
	}

	public function collect( array $raw, array $element ): mixed {
		return array_map( static fn ( IncomingFile $file ): string => '' !== $file->name ? $file->name : 'upload', IncomingFile::files( $raw[ $element['id'] ] ?? null ) );
	}

	public function validate( mixed $value, array $element ): array {
		return is_array( $value ) && count( $value ) <= $this->max_files( $element ) ? array() : array(
			array(
				'code'   => 'max_files',
				'params' => array( 'max' => $this->max_files( $element ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param array<string, mixed> $element
	 * @return list<array{code: string, params?: array<string, string|int>}>
	 */
	public function validate_uploads( array $raw, array $element ): array {
		foreach ( IncomingFile::files( $raw[ $element['id'] ] ?? null ) as $file ) {
			if ( in_array( $file->error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				return array( array( 'code' => 'file_size' ) );
			}
			if ( UPLOAD_ERR_OK !== $file->error || ! is_uploaded_file( $file->temporary ) ) {
				return array( array( 'code' => 'file_upload' ) );
			}
			$size = filesize( $file->temporary );
			if ( false === $size || $size < 1 || $size > $this->max_size( $element ) ) {
				return array( array( 'code' => 'file_size' ) );
			}
			$ext      = strtolower( pathinfo( $file->name, PATHINFO_EXTENSION ) );
			$mime     = $this->mimes( $element )[ $ext ] ?? '';
			$actual   = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file->temporary );
			$accepted = array( $mime );
			if ( 'csv' === $ext ) {
				$accepted[] = 'text/plain';
			}

			if ( 'zip' === $ext ) {
				$accepted[] = 'application/x-zip';
				$accepted[] = 'application/x-zip-compressed';
			}

			if ( '' === $mime || ! in_array( $actual, $accepted, true ) ) {
				return array( array( 'code' => 'file_type' ) );
			}
			if ( str_starts_with( $mime, 'image/' ) && wp_get_image_mime( $file->temporary ) !== $mime ) {
				return array( array( 'code' => 'file_type' ) );
			}
		}
		return array();
	}

	public function format_text( mixed $value, array $element ): string {
		return implode( "\n", array_map( static fn ( mixed $file ): string => is_array( $file ) ? (string) ( $file['name'] ?? '' ) . ': ' . (string) ( $file['url'] ?? '' ) : ( is_string( $file ) ? $file : '' ), is_array( $value ) ? $value : array() ) );
	}

	/**
	 * Type-specific attributes and enhancements without duplicating the upload control.
	 *
	 * @param array<string, mixed> $element
	 * @return array<string, string|int|bool|null>
	 */
	protected function upload_attributes( array $element ): array {
		return array();
	}

	/** @param array<string, mixed> $element */
	protected function upload_extra( array $element ): string {
		return '';
	}

	public function render( array $element, RenderContext $context ): string {
		$id                        = (string) $element['id'];
		$hint                      = $context->dom_id( $id ) . '-file-hint';
		$aria                      = $this->aria( $element, $context );
		$aria['aria-describedby'] .= ' ' . $hint;
		$control                   = '<div class="swf-upload"><input' . $this->attrs(
			array_merge(
				$aria,
				array(
					'type'     => 'file',
					'id'       => $context->dom_id( $id ),
					'name'     => $context->name( $id ) . '[]',
					'multiple' => (bool) $this->setting( $element, 'multiple', false ),
					'accept'   => implode( ',', array_map( static fn ( string $ext ): string => '.' . $ext, array_keys( $this->mimes( $element ) ) ) ),
					'class'    => 'swf-input swf-file',
				),
				$this->upload_attributes( $element )
			)
		) . '></div>';
		/* translators: 1: number of files, 2: size, 3: file extensions */
		$text = sprintf( __( 'Choose or drop up to %1$d file(s), %2$s each. Allowed: %3$s. After a page reload, select files again.', 'sw-forms' ), $this->max_files( $element ), size_format( $this->max_size( $element ) ), implode( ', ', array_keys( $this->mimes( $element ) ) ) );
		return $this->wrap( $element, $context, $control . '<p id="' . esc_attr( $hint ) . '" class="swf-description">' . esc_html( $text ) . '</p>' . $this->upload_extra( $element ) );
	}
}
