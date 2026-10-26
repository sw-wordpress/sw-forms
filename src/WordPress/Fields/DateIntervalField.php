<?php
/**
 * Date interval.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class DateIntervalField extends IntervalField {
	public function type(): string {
		return 'date-interval';
	}

	public function label(): string {
		return __( 'Date interval', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_date_range';
	}

	protected function input_type(): string {
		return 'date';
	}
}
