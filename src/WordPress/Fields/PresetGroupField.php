<?php
/**
 * Group preset whose initial components are copied once, then edited as normal fields.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

abstract class PresetGroupField extends GroupField {

	public function category(): string {
		return 'basic'; }

	/**
	 * Component templates without ids. Existing children are never overwritten or reinserted.
	 *
	 * @return list<array<string, mixed>>
	 */
	abstract public function initial_children(): array;

	/**
	 * @return array<string, mixed>
	 */
	protected function input_part( string $key, string $label, string $autocomplete, bool $required, int $max_length ): array {
		return array(
			'type'        => 'input',
			'key'         => $key,
			'label'       => $label,
			'required'    => $required,
			'description' => '',
			'logic'       => null,
			'settings'    => array(
				'inputType'    => 'text',
				'autocomplete' => $autocomplete,
				'maxLength'    => $max_length,
			),
		);
	}
}
