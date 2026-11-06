<?php
/**
 * Image-only uploads with progressive local preview and native mobile capture hints.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Uploads\IncomingFile;

class ImageField extends FileField {
	private const EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	public function type(): string {
		return 'image';
	}

	public function label(): string {
		return __( 'Image upload', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_add_photo_alternate';
	}

	public function settings_schema(): array {
		$schema = parent::settings_schema();
		foreach ( $schema as &$setting ) {
			if ( 'extensions' === $setting['name'] ) {
				$setting['default'] = implode( ',', self::EXTENSIONS );
				$setting['help']    = __( 'Comma-separated: jpg, jpeg, png, gif, webp. SVG and non-image files are not accepted.', 'sw-forms' );
			}
		}
		unset( $setting );
		$schema[] = array(
			'name'    => 'preview',
			'type'    => 'toggle',
			'tab'     => 'general',
			'label'   => __( 'Show image previews', 'sw-forms' ),
			'default' => true,
			'help'    => __( 'Previews stay in the browser. Images are uploaded only when the form is submitted.', 'sw-forms' ),
		);
		$schema[] = array(
			'name'    => 'crop',
			'type'    => 'toggle',
			'tab'     => 'general',
			'label'   => __( 'Allow image cropping', 'sw-forms' ),
			'default' => false,
			'help'    => __( 'Requires previews and PNG uploads. Applying a crop replaces that selection with a PNG, up to 4096 pixels per side. Animation and metadata are not preserved.', 'sw-forms' ),
		);
		$schema[] = array(
			'name'    => 'capture',
			'type'    => 'select',
			'tab'     => 'general',
			'label'   => __( 'Camera preference', 'sw-forms' ),
			'default' => '',
			'help'    => __( 'A hint for supported mobile browsers. Visitors can still use the normal file picker where camera capture is unavailable.', 'sw-forms' ),
			'choices' => array(
				array(
					'label' => __( 'File picker', 'sw-forms' ),
					'value' => '',
				),
				array(
					'label' => __( 'Rear camera', 'sw-forms' ),
					'value' => 'environment',
				),
				array(
					'label' => __( 'Front camera', 'sw-forms' ),
					'value' => 'user',
				),
			),
		);
		return $schema;
	}

	public function normalize_settings( array $settings ): array {
		$settings               = parent::normalize_settings( $settings );
		$settings['extensions'] = implode( ',', array_intersect( explode( ',', $settings['extensions'] ), self::EXTENSIONS ) );
		return $settings;
	}

	public function mimes( array $element ): array {
		$element['settings'] = array_merge( $this->default_settings(), is_array( $element['settings'] ?? null ) ? $element['settings'] : array() );
		return array_intersect_key( parent::mimes( $element ), array_flip( self::EXTENSIONS ) );
	}

	public function validate_uploads( array $raw, array $element ): array {
		$errors = parent::validate_uploads( $raw, $element );
		if ( array() !== $errors ) {
			return $errors;
		}
		foreach ( IncomingFile::files( $raw[ $element['id'] ] ?? null ) as $file ) {
			$dimensions = wp_getimagesize( $file->temporary );
			if ( ! is_array( $dimensions ) || $dimensions[0] < 1 || $dimensions[1] < 1 ) {
				return array( array( 'code' => 'image' ) );
			}
		}
		return array();
	}

	protected function upload_attributes( array $element ): array {
		$capture = (string) $this->setting( $element, 'capture', '' );
		return array(
			'accept'                 => implode( ',', array_unique( array_values( $this->mimes( $element ) ) ) ),
			'capture'                => in_array( $capture, array( 'user', 'environment' ), true ) ? $capture : null,
			'data-swf-image'         => (bool) $this->setting( $element, 'preview', true ),
			'data-swf-preview-limit' => $this->max_files( $element ),
			'data-swf-preview-error' => __( 'Preview unavailable. The image will be checked when you submit the form.', 'sw-forms' ),
			'data-swf-crop'          => (bool) $this->setting( $element, 'crop', false ) && isset( $this->mimes( $element )['png'] ),
			'data-swf-crop-labels'   => wp_json_encode(
				array(
					'crop'   => __( 'Crop image', 'sw-forms' ),
					'x'      => __( 'Left (%)', 'sw-forms' ),
					'y'      => __( 'Top (%)', 'sw-forms' ),
					'width'  => __( 'Width (%)', 'sw-forms' ),
					'height' => __( 'Height (%)', 'sw-forms' ),
					'apply'  => __( 'Apply crop', 'sw-forms' ),
					'cancel' => __( 'Cancel crop', 'sw-forms' ),
					'error'  => __( 'The crop could not be applied. The selected file is unchanged.', 'sw-forms' ),
				)
			),
		);
	}

	protected function upload_extra( array $element ): string {
		return $this->setting( $element, 'preview', true ) ? '<div class="swf-image-previews" aria-live="polite" hidden></div>' : '';
	}
}
