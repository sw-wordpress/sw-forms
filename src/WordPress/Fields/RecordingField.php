<?php
/**
 * Optional browser recording with a native file fallback and stream validation.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Uploads\IncomingFile;

abstract class RecordingField extends FileField {
	public function max_files( array $element ): int {
		return 1;
	}

	protected function allowed_mimes(): array {
		return 'audio' === $this->type()
			? array(
				'webm' => 'video/webm',
				'ogg'  => 'audio/ogg',
				'm4a'  => 'audio/mpeg',
				'mp3'  => 'audio/mpeg',
				'wav'  => 'audio/wav',
				'mp4'  => 'video/mp4',
			)
			: array(
				'webm' => 'video/webm',
				'mp4'  => 'video/mp4',
			);
	}

	protected function detected_mimes( string $ext, string $mime ): array {
		return match ( $ext ) {
			'm4a' => array( 'audio/mp4', 'video/mp4', 'audio/x-m4a' ),
			'wav' => array( 'audio/wav', 'audio/x-wav', 'audio/vnd.wave' ),
			default => parent::detected_mimes( $ext, $mime ),
		};
	}

	public function settings_schema(): array {
		$schema = array_values( array_filter( parent::settings_schema(), static fn ( array $setting ): bool => in_array( $setting['name'], array( 'maxSize', 'extensions' ), true ) ) );
		foreach ( $schema as &$setting ) {
			if ( 'extensions' === $setting['name'] ) {
				$setting['default'] = implode( ',', array_keys( $this->allowed_mimes() ) );
				$setting['help']    = __( 'Only the listed audio/video formats are supported. WordPress restrictions also apply.', 'sw-forms' );
			}
		}
		unset( $setting );
		$schema[] = array(
			'name'    => 'maxSeconds',
			'type'    => 'number',
			'tab'     => 'validation',
			'label'   => __( 'Recording time limit (seconds)', 'sw-forms' ),
			'help'    => __( 'Limits browser recording only. Uploaded media is checked for file type, stream and size; duration is not enforced on the server.', 'sw-forms' ),
			'default' => 60,
			'min'     => 1,
			'max'     => 600,
			'integer' => true,
		);
		return $schema;
	}

	public function validate_uploads( array $raw, array $element ): array {
		$errors = parent::validate_uploads( $raw, $element );
		if ( array() !== $errors ) {
			return $errors;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		foreach ( IncomingFile::files( $raw[ $element['id'] ] ?? null ) as $file ) {
			$meta  = wp_read_video_metadata( $file->temporary );
			$video = ! empty( $meta['width'] ) && ! empty( $meta['height'] );
			$audio = ! empty( $meta['audio']['channels'] );
			if ( 'video' === $this->type() ? ! $video : ( ! $audio || $video ) ) {
				return array( array( 'code' => 'media_stream' ) );
			}
		}
		return array();
	}

	protected function upload_attributes( array $element ): array {
		return array(
			'data-swf-recording'      => $this->type(),
			'data-swf-record-seconds' => (int) $this->setting( $element, 'maxSeconds', 60 ),
			'data-swf-record-bytes'   => $this->max_size( $element ),
		);
	}

	protected function upload_extra( array $element ): string {
		return '<div class="swf-recorder" hidden><p>' . esc_html__( 'Recording requires HTTPS and your permission. Nothing is uploaded until you submit the form. You can also choose a file above.', 'sw-forms' ) . '</p><button type="button" class="swf-record-start">' . esc_html__( 'Start recording', 'sw-forms' ) . '</button><button type="button" class="swf-record-stop" disabled>' . esc_html__( 'Stop recording', 'sw-forms' ) . '</button><button type="button" class="swf-record-clear">' . esc_html__( 'Discard recording', 'sw-forms' ) . '</button><p role="status" class="swf-media-status" data-error="' . esc_attr__( 'Recording is unavailable or permission was denied. Please choose a file instead.', 'sw-forms' ) . '" data-active="' . esc_attr__( 'Recording…', 'sw-forms' ) . '" data-ready="' . esc_attr__( 'Recording selected. You can play it before submitting.', 'sw-forms' ) . '" data-limit="' . esc_attr__( 'The recording exceeds the size limit. Please record a shorter clip.', 'sw-forms' ) . '"></p></div>';
	}
}
