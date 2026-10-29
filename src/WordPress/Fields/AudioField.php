<?php
/**
 * Audio recording field.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class AudioField extends RecordingField {
	public function type(): string {
		return 'audio';
	}

	public function label(): string {
		return __( 'Audio recording', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_mic';
	}
}
