<?php
/**
 * Editable name group with stable component keys.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class NameField extends PresetGroupField {
	public function type(): string {
		return 'name';
	}
	public function label(): string {
		return __( 'Name', 'sw-forms' );
	}
	public function icon(): string {
		return 'r_person';
	}
	public function initial_children(): array {
		return array(
			$this->input_part( 'salutation', __( 'Salutation', 'sw-forms' ), 'honorific-prefix', false, 60 ),
			$this->input_part( 'given_name', __( 'Given name', 'sw-forms' ), 'given-name', true, 120 ),
			$this->input_part( 'family_name', __( 'Family name', 'sw-forms' ), 'family-name', true, 120 ),
			$this->input_part( 'title', __( 'Title', 'sw-forms' ), '', false, 60 ),
		);
	}
}
