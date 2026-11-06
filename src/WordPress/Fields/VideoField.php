<?php
/**
 * Video recording field.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class VideoField extends RecordingField {
	public function type(): string {
		return 'video';
	}

	public function label(): string {
		return __( 'Video recording', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_videocam';
	}
}
