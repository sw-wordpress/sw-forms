<?php
/**
 * Single checkbox (consent, yes/no) or a list of checkboxes.
 *
 * Value: bool in single mode, list of option values in multiple mode.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class CheckboxField extends ChoiceField {

	public function type(): string {
		return 'checkbox';
	}

	public function label(): string {
		return __( 'Checkbox', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_check_box';
	}

	public function settings_schema(): array {
		$single   = array(
			'setting' => 'mode',
			'equals'  => array( 'single' ),
		);
		$multiple = array(
			'setting' => 'mode',
			'equals'  => array( 'multiple' ),
		);
		return array(
			array(
				'name'    => 'mode',
				'type'    => 'select',
				'tab'     => 'general',
				'label'   => __( 'Mode', 'sw-forms' ),
				'default' => 'multiple',
				'choices' => array(
					array(
						'value' => 'multiple',
						'label' => __( 'List of options', 'sw-forms' ),
					),
					array(
						'value' => 'single',
						'label' => __( 'Single checkbox (e.g. consent)', 'sw-forms' ),
					),
				),
			),
			array(
				'name'    => 'text',
				'type'    => 'html',
				'tab'     => 'general',
				'label'   => __( 'Checkbox text', 'sw-forms' ),
				'help'    => __( 'Shown next to the box. Links are allowed. Empty: the label is used.', 'sw-forms' ),
				'default' => '',
				'showIf'  => $single,
			),
			array(
				'name'    => 'checked',
				'type'    => 'toggle',
				'tab'     => 'general',
				'label'   => __( 'Checked by default', 'sw-forms' ),
				'default' => false,
				'showIf'  => $single,
			),
			$this->options_setting( $multiple ),
			array(
				'name'    => 'defaultValues',
				'type'    => 'list',
				'tab'     => 'general',
				'label'   => __( 'Checked by default', 'sw-forms' ),
				'default' => array(),
				'showIf'  => $multiple,
			),
			array(
				'name'    => 'minSelected',
				'type'    => 'number',
				'integer' => true,
				'min'     => 0,
				'tab'     => 'validation',
				'label'   => __( 'Minimum selections', 'sw-forms' ),
				'default' => null,
				'showIf'  => $multiple,
			),
			array(
				'name'    => 'maxSelected',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'tab'     => 'validation',
				'label'   => __( 'Maximum selections', 'sw-forms' ),
				'default' => null,
				'showIf'  => $multiple,
			),
			$this->layout_setting( $multiple ),
			$this->presentation_setting() + array( 'showIf' => $multiple ),
			array(
				'name'    => 'switch',
				'type'    => 'toggle',
				'tab'     => 'appearance',
				'label'   => __( 'Display as switch', 'sw-forms' ),
				'default' => false,
				'showIf'  => $single,
			),
		);
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function single( array $element ): bool {
		return 'single' === $this->setting( $element, 'mode' );
	}

	public function default_value( array $element ): mixed {
		if ( $this->single( $element ) ) {
			return (bool) $this->setting( $element, 'checked', false );
		}
		$defaults = $this->setting( $element, 'defaultValues', array() );
		return array_values( array_intersect( is_array( $defaults ) ? $defaults : array(), array_keys( $this->options( $element ) ) ) );
	}

	public function collect( array $raw, array $element ): mixed {
		$value = $raw[ $element['id'] ] ?? null;
		if ( $this->single( $element ) ) {
			return is_scalar( $value ) && '' !== (string) $value && '0' !== (string) $value;
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
		if ( $this->single( $element ) || ! is_array( $value ) ) {
			return array();
		}
		$allowed = array_keys( $this->options( $element ) );
		foreach ( $value as $item ) {
			if ( ! in_array( $item, $allowed, true ) ) {
				return array( array( 'code' => 'option' ) );
			}
		}
		$count = count( $value );
		$min   = $this->setting( $element, 'minSelected' );
		$max   = $this->setting( $element, 'maxSelected' );
		if ( is_int( $min ) && $count < $min ) {
			return array(
				array(
					'code'   => 'min_selected',
					'params' => array( 'min' => $min ),
				),
			);
		}
		if ( is_int( $max ) && $count > $max ) {
			return array(
				array(
					'code'   => 'max_selected',
					'params' => array( 'max' => $max ),
				),
			);
		}
		return array();
	}

	public function format_text( mixed $value, array $element ): string {
		if ( $this->single( $element ) ) {
			return $value ? __( 'Yes', 'sw-forms' ) : __( 'No', 'sw-forms' );
		}
		$options = $this->options( $element );
		$labels  = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			$labels[] = $options[ (string) $item ] ?? (string) $item;
		}
		return implode( ', ', $labels );
	}

	public function value_kind( array $element ): string {
		return $this->single( $element ) ? 'bool' : 'list';
	}

	public function render( array $element, RenderContext $context ): string {
		$id     = (string) $element['id'];
		$dom_id = $context->dom_id( $id );
		$value  = $context->value( $id );

		if ( $this->single( $element ) ) {
			$text    = (string) $this->setting( $element, 'text', '' );
			$control = $this->choice(
				array_merge(
					array(
						'type'    => 'checkbox',
						'role'    => $this->setting( $element, 'switch', false ) ? 'switch' : null,
						'class'   => $this->setting( $element, 'switch', false ) ? 'swf-switch' : null,
						'id'      => $dom_id,
						'name'    => $context->name( $id ),
						'value'   => '1',
						'checked' => (bool) $value,
					),
					$this->aria( $element, $context )
				),
				'' !== $text ? wp_kses( $text, self::inline_html() ) . $this->required_marker( $element ) : esc_html( (string) ( $element['label'] ?? '' ) ) . $this->required_marker( $element )
			);
			if ( '' === $text ) {
				// The label sits next to the box; no separate heading.
				$element['label'] = '';
			}
			return $this->wrap( $element, $context, $control, '' !== $text );
		}

		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();
		$controls = '';
		$index    = 0;
		foreach ( $this->options( $element ) as $option_value => $option_label ) {
			$controls .= $this->choice(
				array(
					'type'         => 'checkbox',
					'id'           => $dom_id . '-' . ( $index++ ),
					'name'         => $context->name( $id, '[]' ),
					'value'        => (string) $option_value,
					'checked'      => in_array( (string) $option_value, $selected, true ),
					'aria-invalid' => $this->invalid_attr( $element, $context ),
				),
				$this->option_label( $element, (string) $option_value, $option_label )
			);
		}
		return $this->wrap( $element, $context, '<div class="' . esc_attr( $this->list_class( $element ) ) . '">' . $controls . '</div>', true );
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function required_marker( array $element ): string {
		return ! empty( $element['required'] )
			? '<span class="swf-required" aria-hidden="true">*</span><span class="swf-sr">' . esc_html__( '(required)', 'sw-forms' ) . '</span>'
			: '';
	}
}
