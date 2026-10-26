<?php
/**
 * Radio buttons with an optional free text "other" option.
 *
 * The "other" text is posted as `[<id>:other]`; the clean value is the typed text.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class RadioField extends ChoiceField {

	public const OTHER = '__other__';

	public function type(): string {
		return 'radio';
	}

	public function label(): string {
		return __( 'Radio buttons', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_radio_button_checked';
	}

	public function settings_schema(): array {
		return array(
			$this->options_setting(),
			array(
				'name'    => 'default',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Selected by default (option value)', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'other',
				'type'    => 'toggle',
				'tab'     => 'general',
				'label'   => __( 'Offer "Other" with free text', 'sw-forms' ),
				'default' => false,
			),
			array(
				'name'    => 'otherLabel',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Label of "Other"', 'sw-forms' ),
				'default' => __( 'Other', 'sw-forms' ),
				'showIf'  => array(
					'setting' => 'other',
					'equals'  => array( true ),
				),
			),
			$this->layout_setting(),
			$this->presentation_setting(),
		);
	}

	public function collect( array $raw, array $element ): mixed {
		$id    = (string) $element['id'];
		$value = $raw[ $id ] ?? '';
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( (string) $value );
		if ( self::OTHER === $value && $this->setting( $element, 'other' ) ) {
			$other = $raw[ $id . ':other' ] ?? '';
			return is_scalar( $other ) ? $this->limit( sanitize_text_field( (string) $other ), 500 ) : '';
		}
		return $value;
	}

	public function validate( mixed $value, array $element ): array {
		if ( $this->setting( $element, 'other' ) || array_key_exists( (string) $value, $this->options( $element ) ) ) {
			return array();
		}
		return array( array( 'code' => 'option' ) );
	}

	public function format_text( mixed $value, array $element ): string {
		return $this->options( $element )[ (string) $value ] ?? (string) $value;
	}

	public function value_kind( array $element ): string {
		return 'radio';
	}

	public function render( array $element, RenderContext $context ): string {
		$id       = (string) $element['id'];
		$dom_id   = $context->dom_id( $id );
		$value    = $context->value( $id );
		$value    = is_scalar( $value ) ? (string) $value : '';
		$options  = $this->options( $element );
		$is_other = '' !== $value && ! array_key_exists( $value, $options ) && $this->setting( $element, 'other' );
		$invalid  = $this->invalid_attr( $element, $context );
		$controls = '';
		$index    = 0;
		foreach ( $options as $option_value => $option_label ) {
			$controls .= $this->choice(
				array(
					'type'         => 'radio',
					'id'           => $dom_id . '-' . ( $index++ ),
					'name'         => $context->name( $id ),
					'value'        => (string) $option_value,
					'checked'      => (string) $option_value === $value,
					'aria-invalid' => $invalid,
				),
				$this->option_label( $element, (string) $option_value, $option_label )
			);
		}
		if ( $this->setting( $element, 'other' ) ) {
			$controls .= '<div class="swf-choice swf-choice--other"><input' . $this->attrs(
				array(
					'type'         => 'radio',
					'id'           => $dom_id . '-other',
					'name'         => $context->name( $id ),
					'value'        => self::OTHER,
					'checked'      => $is_other,
					'aria-invalid' => $invalid,
				)
			) . '><label for="' . esc_attr( $dom_id . '-other' ) . '">' . esc_html( (string) $this->setting( $element, 'otherLabel', '' ) ) . '</label>'
				. '<input' . $this->attrs(
					array(
						'type'       => 'text',
						'class'      => 'swf-input swf-other-text',
						'name'       => $context->name( $id . ':other' ),
						'value'      => $is_other ? $value : '',
						'aria-label' => (string) $this->setting( $element, 'otherLabel', '' ),
					)
				) . '></div>';
		}
		return $this->wrap( $element, $context, '<div class="' . esc_attr( $this->list_class( $element ) ) . '" role="radiogroup">' . $controls . '</div>', true );
	}
}
