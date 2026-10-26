<?php
/**
 * Star rating.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class RatingField extends ScoreField {
	public function type(): string {
		return 'rating';
	}

	public function label(): string {
		return __( 'Rating', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_star';
	}
}
