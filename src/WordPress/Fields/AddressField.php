<?php
/**
 * Editable address group; postal codes remain text and no national format is assumed.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class AddressField extends PresetGroupField {
	public function type(): string {
		return 'address';
	}
	public function label(): string {
		return __( 'Address', 'sw-forms' );
	}
	public function icon(): string {
		return 'r_location_on';
	}
	public function initial_children(): array {
		return array(
			$this->input_part( 'street', __( 'Street', 'sw-forms' ), 'address-line1', true, 200 ),
			$this->input_part( 'house_number', __( 'House number', 'sw-forms' ), '', false, 50 ),
			$this->input_part( 'postal_code', __( 'Postal code', 'sw-forms' ), 'postal-code', false, 30 ),
			$this->input_part( 'city', __( 'City', 'sw-forms' ), 'address-level2', true, 120 ),
			$this->input_part( 'country', __( 'Country', 'sw-forms' ), 'country-name', true, 120 ),
		);
	}
}
