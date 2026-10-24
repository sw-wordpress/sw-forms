<?php
/**
 * Drop-down (single) or list box (multiple).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class SelectField extends ChoiceField {

	public function type(): string {
		return 'select';
	}

	public function label(): string {
		return __( 'Select', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_arrow_drop_down_circle';
	}

	public function settings_schema(): array {
		$single = array(
			'setting' => 'multiple',
			'equals'  => array( false ),
		);
		return array(
			$this->options_setting(),
			array(
				'name'    => 'multiple',
				'type'    => 'toggle',
				'tab'     => 'general',
				'label'   => __( 'Allow multiple selections', 'sw-forms' ),
				'default' => false,
			),
			array(
				'name'    => 'placeholder',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Empty choice text', 'sw-forms' ),
				'default' => __( 'Please choose', 'sw-forms' ),
				'showIf'  => $single,
			),
			array(
				'name'    => 'default',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Selected by default (option value)', 'sw-forms' ),
				'default' => '',
				'showIf'  => $single,
			),
		);
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function multiple( array $element ): bool {
		return (bool) $this->setting( $element, 'multiple', false );
	}

	public function default_value( array $element ): mixed {
		return $this->multiple( $element ) ? array() : parent::default_value( $element );
	}

	public function collect( array $raw, array $element ): mixed {
		$value = $raw[ $element['id'] ] ?? null;
		if ( ! $this->multiple( $element ) ) {
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		$list = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_scalar( $item ) ) {
				$list[] = sanitize_text_field( (string) $item );
			}
		}
		return array_values( array_unique( $list ) );
	}

	public function validate( mixed $value, array $element ): array {
		$options = $this->options( $element );
		foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
			if ( ! array_key_exists( (string) $item, $options ) ) {
				return array( array( 'code' => 'option' ) );
			}
		}
		return array();
	}

	public function format_text( mixed $value, array $element ): string {
		$options = $this->options( $element );
		$labels  = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
			$labels[] = $options[ (string) $item ] ?? (string) $item;
		}
		return implode( ', ', $labels );
	}

	public function value_kind( array $element ): string {
		return $this->multiple( $element ) ? 'list' : 'text';
	}

	public function render( array $element, RenderContext $context ): string {
		$id       = (string) $element['id'];
		$multiple = $this->multiple( $element );
		$value    = $context->value( $id );
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array( is_scalar( $value ) ? (string) $value : '' );
		$options  = '';
		if ( ! $multiple ) {
			$options .= '<option value="">' . esc_html( (string) $this->setting( $element, 'placeholder', '' ) ) . '</option>';
		}
		foreach ( $this->options( $element ) as $option_value => $option_label ) {
			$options .= '<option' . $this->attrs(
				array(
					'value'    => (string) $option_value,
					'selected' => in_array( (string) $option_value, $selected, true ),
				)
			) . '>' . esc_html( $option_label ) . '</option>';
		}
		$control = '<select' . $this->attrs(
			array_merge(
				array(
					'id'       => $context->dom_id( $id ),
					'name'     => $context->name( $id, $multiple ? '[]' : '' ),
					'class'    => 'swf-select',
					'multiple' => $multiple,
					'size'     => $multiple ? min( 8, max( 3, count( $this->options( $element ) ) ) ) : null,
				),
				$this->aria( $element, $context )
			)
		) . '>' . $options . '</select>';
		return $this->wrap( $element, $context, $control );
	}
}
