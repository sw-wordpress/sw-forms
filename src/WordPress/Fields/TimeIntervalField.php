<?php
/**
 * Same-day time interval.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class TimeIntervalField extends IntervalField {
	public function type(): string {
		return 'time-interval';
	}

	public function label(): string {
		return __( 'Time interval', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_schedule';
	}

	protected function input_type(): string {
		return 'time';
	}
}
