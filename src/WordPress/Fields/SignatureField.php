<?php
/**
 * Drawn signatures are ordinary, validated PNG multipart uploads.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class SignatureField extends ImageField {
	public function type(): string {
		return 'signature';
	}

	public function label(): string {
		return __( 'Signature', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_draw';
	}

	public function settings_schema(): array {
		return array_values( array_filter( parent::settings_schema(), static fn ( array $setting ): bool => 'maxSize' === $setting['name'] ) );
	}

	public function default_settings(): array {
		return parent::default_settings() + array(
			'extensions' => 'png',
			'multiple'   => false,
			'preview'    => true,
			'crop'       => false,
			'capture'    => '',
		);
	}

	public function normalize_settings( array $settings ): array {
		return SettingsNormalizer::normalize( $this->settings_schema(), $settings ) + $this->default_settings();
	}

	public function max_files( array $element ): int {
		return 1;
	}

	protected function allowed_mimes(): array {
		return array( 'png' => 'image/png' );
	}

	public function mimes( array $element ): array {
		return array_intersect_key( parent::mimes( $element ), $this->allowed_mimes() );
	}

	protected function upload_attributes( array $element ): array {
		return parent::upload_attributes( $element ) + array( 'data-swf-signature' => true );
	}

	protected function upload_extra( array $element ): string {
		return parent::upload_extra( $element ) . '<div class="swf-signature" hidden><p>' . esc_html__( 'Draw with a mouse, pen or touch, then use the signature. Alternatively choose a PNG file above.', 'sw-forms' ) . '</p><canvas width="800" height="300" aria-label="' . esc_attr__( 'Signature drawing area', 'sw-forms' ) . '"></canvas><button type="button" class="swf-signature-use">' . esc_html__( 'Use signature', 'sw-forms' ) . '</button><button type="button" class="swf-signature-clear">' . esc_html__( 'Clear signature', 'sw-forms' ) . '</button><p role="status" class="swf-media-status" data-error="' . esc_attr__( 'The signature could not be used. Please choose a PNG file instead.', 'sw-forms' ) . '" data-ready="' . esc_attr__( 'Signature selected.', 'sw-forms' ) . '"></p></div>';
	}
}
