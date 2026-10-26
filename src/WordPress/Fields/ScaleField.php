<?php
/**
 * Numeric rating scale.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class ScaleField extends ScoreField {
	public function type(): string {
		return 'scale';
	}

	public function label(): string {
		return __( 'Scale', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_linear_scale';
	}
}
