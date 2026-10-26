<?php
/**
 * Single line input (text, email, tel, url, number, date, password, hidden).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Validation\Rules;
use SWForms\WordPress\Frontend\RenderContext;

final class InputField extends AbstractFieldType {

	public const INPUT_TYPES = array( 'text', 'email', 'tel', 'url', 'number', 'date', 'time', 'datetime-local', 'password', 'hidden' );

	public function type(): string {
		return 'input';
	}

	public function label(): string {
		return __( 'Input', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_short_text';
	}

	public function category(): string {
		return 'basic';
	}

	public function settings_schema(): array {
		$number_only = array(
			'setting' => 'inputType',
			'equals'  => array( 'number' ),
		);
		$text_like   = array(
			'setting' => 'inputType',
			'equals'  => array( 'text', 'email', 'tel', 'url', 'password' ),
		);
		return array(
			array(
				'name'    => 'inputType',
				'type'    => 'select',
				'tab'     => 'general',
				'label'   => __( 'Input type', 'sw-forms' ),
				'default' => 'text',
				'choices' => array(
					array(
						'value' => 'text',
						'label' => __( 'Text', 'sw-forms' ),
					),
					array(
						'value' => 'email',
						'label' => __( 'Email', 'sw-forms' ),
					),
					array(
						'value' => 'tel',
						'label' => __( 'Phone', 'sw-forms' ),
					),
					array(
						'value' => 'url',
						'label' => __( 'URL', 'sw-forms' ),
					),
					array(
						'value' => 'number',
						'label' => __( 'Number', 'sw-forms' ),
					),
					array(
						'value' => 'date',
						'label' => __( 'Date', 'sw-forms' ),
					),
					array(
						'value' => 'time',
						'label' => __( 'Time', 'sw-forms' ),
					),
					array(
						'value' => 'datetime-local',
						'label' => __( 'Date and time', 'sw-forms' ),
					),
					array(
						'value' => 'password',
						'label' => __( 'Password', 'sw-forms' ),
					),
					array(
						'value' => 'hidden',
						'label' => __( 'Hidden', 'sw-forms' ),
					),
				),
			),
			array(
				'name'    => 'validate',
				'type'    => 'toggle',
				'tab'     => 'validation',
				'label'   => __( 'Validate value', 'sw-forms' ),
				'help'    => __( 'Disable format and range checks. Required fields are still checked.', 'sw-forms' ),
				'default' => true,
			),
			array(
				'name'    => 'integer',
				'type'    => 'toggle',
				'tab'     => 'validation',
				'label'   => __( 'Whole numbers only', 'sw-forms' ),
				'default' => false,
				'showIf'  => $number_only,
			),
			array(
				'name'    => 'placeholder',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Placeholder', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'default',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Default value', 'sw-forms' ),
				'help'    => __( 'Supports {user:email}, {user:display_name} and {get:parameter}.', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'minLength',
				'type'    => 'number',
				'integer' => true,
				'min'     => 0,
				'tab'     => 'validation',
				'label'   => __( 'Minimum length', 'sw-forms' ),
				'default' => null,
				'showIf'  => $text_like,
			),
			array(
				'name'    => 'maxLength',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'tab'     => 'validation',
				'label'   => __( 'Maximum length', 'sw-forms' ),
				'default' => null,
				'showIf'  => $text_like,
			),
			array(
				'name'    => 'min',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Minimum', 'sw-forms' ),
				'default' => null,
				'showIf'  => $number_only,
			),
			array(
				'name'    => 'max',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Maximum', 'sw-forms' ),
				'default' => null,
				'showIf'  => $number_only,
			),
			array(
				'name'    => 'step',
				'type'    => 'number',
				'min'     => 0,
				'tab'     => 'validation',
				'label'   => __( 'Step', 'sw-forms' ),
				'default' => null,
				'showIf'  => $number_only,
			),
			array(
				'name'    => 'minDate',
				'type'    => 'text',
				'tab'     => 'validation',
				'label'   => __( 'Earliest value', 'sw-forms' ),
				'default' => '',
				'showIf'  => array(
					'setting' => 'inputType',
					'equals'  => array( 'date', 'time', 'datetime-local' ),
				),
			),
			array(
				'name'    => 'maxDate',
				'type'    => 'text',
				'tab'     => 'validation',
				'label'   => __( 'Latest value', 'sw-forms' ),
				'default' => '',
				'showIf'  => array(
					'setting' => 'inputType',
					'equals'  => array( 'date', 'time', 'datetime-local' ),
				),
			),
			array(
				'name'    => 'timeStep',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Time interval (seconds)', 'sw-forms' ),
				'default' => null,
				'min'     => 1,
				'integer' => true,
				'showIf'  => array(
					'setting' => 'inputType',
					'equals'  => array( 'time', 'datetime-local' ),
				),
			),
			array(
				'name'    => 'pattern',
				'type'    => 'code',
				'tab'     => 'validation',
				'label'   => __( 'Pattern (regular expression)', 'sw-forms' ),
				'help'    => __( 'The whole value must match, e.g. [0-9]{5} for a German postal code.', 'sw-forms' ),
				'default' => '',
				'showIf'  => $text_like,
			),
			array(
				'name'    => 'patternMessage',
				'type'    => 'text',
				'tab'     => 'validation',
				'label'   => __( 'Message when the pattern does not match', 'sw-forms' ),
				'default' => '',
				'showIf'  => $text_like,
			),
			array(
				'name'    => 'autocomplete',
				'type'    => 'text',
				'tab'     => 'appearance',
				'label'   => __( 'Autocomplete hint', 'sw-forms' ),
				'help'    => __( 'e.g. given-name, family-name, email, postal-code', 'sw-forms' ),
				'default' => '',
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings = parent::normalize_settings( $settings );
		// Only keep the token characters allowed in the HTML autocomplete attribute.
		$settings['autocomplete'] = (string) preg_replace( '/[^a-z0-9 -]/', '', strtolower( (string) $settings['autocomplete'] ) );
		return $settings;
	}

	public function default_value( array $element ): mixed {
		return \SWForms\WordPress\Support\DynamicDefaults::resolve( (string) parent::default_value( $element ) );
	}

	public function collect( array $raw, array $element ): mixed {
		$value = $raw[ $element['id'] ] ?? '';
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		return match ( $this->input_type( $element ) ) {
			'password' => $this->limit( trim( $value ), 500 ),
			'email'    => $this->limit( trim( sanitize_text_field( $value ) ), 254 ),
			'url'      => $this->limit( trim( sanitize_text_field( $value ) ), 2000 ),
			default    => $this->limit( sanitize_text_field( $value ) ),
		};
	}

	public function validate( mixed $value, array $element ): array {
		if ( ! $this->setting( $element, 'validate', true ) ) {
			return array();
		}
		$value = (string) $value;
		$type  = $this->input_type( $element );
		switch ( $type ) {
			case 'email':
				if ( ! Rules::email( $value ) ) {
					return array( array( 'code' => 'email' ) );
				}
				break;
			case 'url':
				if ( ! Rules::url( $value ) ) {
					return array( array( 'code' => 'url' ) );
				}
				break;
			case 'tel':
				if ( ! Rules::tel( $value ) ) {
					return array( array( 'code' => 'tel' ) );
				}
				break;
			case 'date':
			case 'time':
			case 'datetime-local':
				return $this->validate_temporal( $value, $element );
			case 'number':
				return $this->validate_number( $value, $element );
		}

		$length = Rules::length( $value );
		$min    = $this->setting( $element, 'minLength' );
		$max    = $this->setting( $element, 'maxLength' );
		if ( is_int( $min ) && $length < $min ) {
			return array(
				array(
					'code'   => 'minlength',
					'params' => array( 'min' => $min ),
				),
			);
		}
		if ( is_int( $max ) && $length > $max ) {
			return array(
				array(
					'code'   => 'maxlength',
					'params' => array( 'max' => $max ),
				),
			);
		}
		$pattern = (string) $this->setting( $element, 'pattern', '' );
		if ( '' !== $pattern && false === Rules::pattern( $pattern, $value ) ) {
			return array(
				array(
					'code'   => 'pattern',
					'params' => array( 'message' => (string) $this->setting( $element, 'patternMessage', '' ) ),
				),
			);
		}
		return array();
	}

	/**
	 * @param array<string, mixed> $element
	 * @return list<array{code: string, params?: array<string, string|int>}>
	 */
	private function validate_number( string $value, array $element ): array {
		$number = Rules::number( $value );
		if ( null === $number ) {
			return array( array( 'code' => 'number' ) );
		}
		if ( $this->setting( $element, 'integer', false ) && floor( $number ) !== $number ) {
			return array( array( 'code' => 'integer' ) );
		}
		$min = $this->setting( $element, 'min' );
		$max = $this->setting( $element, 'max' );
		if ( is_numeric( $min ) && $number < (float) $min ) {
			return array(
				array(
					'code'   => 'min',
					'params' => array( 'min' => (string) $min ),
				),
			);
		}
		if ( is_numeric( $max ) && $number > (float) $max ) {
			return array(
				array(
					'code'   => 'max',
					'params' => array( 'max' => (string) $max ),
				),
			);
		}
		$step = $this->setting( $element, 'step' );
		if ( is_numeric( $step ) && (float) $step > 0 && ! Rules::step( $number, (float) $step, is_numeric( $min ) ? (float) $min : 0.0 ) ) {
			return array( array( 'code' => 'step' ) );
		}
		return array();
	}

	/**
	 * @param array<string, mixed> $element
	 * @return list<array{code: string, params?: array<string, string|int>}>
	 */
	private function validate_temporal( string $value, array $element ): array {
		$type = $this->input_type( $element );
		if ( ! $this->valid_temporal( $value, $type ) ) {
			return array( array( 'code' => 'date' === $type ? 'date' : 'time' ) );
		}
		foreach ( array(
			'minDate' => 'min',
			'maxDate' => 'max',
		) as $setting => $code ) {
			$bound = (string) $this->setting( $element, $setting, '' );
			if ( $this->valid_temporal( $bound, $type ) ) {
				$comparison = strcmp( $this->temporal_key( $value, $type ), $this->temporal_key( $bound, $type ) );
				if ( ( 'min' === $code && $comparison < 0 ) || ( 'max' === $code && $comparison > 0 ) ) {
					return array(
						array(
							'code'   => $code,
							'params' => array( $code => $bound ),
						),
					);
				}
			}
		}
		$step = $this->setting( $element, 'timeStep' );
		if ( 'date' !== $type && is_numeric( $step ) && (float) $step > 0 ) {
			$min  = (string) $this->setting( $element, 'minDate', '' );
			$base = $this->valid_temporal( $min, $type ) ? $this->temporal_seconds( $min, $type ) : 0;
			if ( ! Rules::step( $this->temporal_seconds( $value, $type ), (float) $step, $base ) ) {
				return array( array( 'code' => 'step' ) );
			}
		}
		return array();
	}

	private function valid_temporal( string $value, string $type ): bool {
		return match ( $type ) {
			'date' => Rules::date( $value ),
			'time' => Rules::time( $value ),
			default => Rules::dateTime( $value ),
		};
	}

	private function temporal_key( string $value, string $type ): string {
		return ( 'time' === $type && 5 === strlen( $value ) ) || ( 'datetime-local' === $type && 16 === strlen( $value ) ) ? $value . ':00' : $value;
	}

	private function temporal_seconds( string $value, string $type ): int {
		// UTC is only a stable arithmetic frame for local wall time; no DST conversion.
		$date = new \DateTimeImmutable( 'time' === $type ? '1970-01-01T' . $value : $value, new \DateTimeZone( 'UTC' ) );
		return $date->getTimestamp();
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function input_type( array $element ): string {
		$type = (string) $this->setting( $element, 'inputType', 'text' );
		return in_array( $type, self::INPUT_TYPES, true ) ? $type : 'text';
	}

	public function render( array $element, RenderContext $context ): string {
		$id    = (string) $element['id'];
		$type  = $this->input_type( $element );
		$value = $context->value( $id );
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( 'password' === $type ) {
			$value = '';
		}

		$attributes = array(
			'type'         => 'number' === $type || ( ! $this->setting( $element, 'validate', true ) && in_array( $type, array( 'email', 'url', 'date', 'time', 'datetime-local' ), true ) ) ? 'text' : $type,
			'inputmode'    => 'number' === $type ? 'decimal' : null,
			'id'           => $context->dom_id( $id ),
			'name'         => $context->name( $id ),
			'value'        => $value,
			'class'        => 'swf-input',
			'placeholder'  => (string) $this->setting( $element, 'placeholder', '' ) ?: null,
			'autocomplete' => (string) $this->setting( $element, 'autocomplete', '' ) ?: null,
			'maxlength'    => $this->setting( $element, 'validate', true ) && is_int( $this->setting( $element, 'maxLength' ) ) ? (int) $this->setting( $element, 'maxLength' ) : null,
		);
		if ( $this->setting( $element, 'validate', true ) && in_array( $type, array( 'date', 'time', 'datetime-local' ), true ) ) {
			foreach ( array(
				'minDate' => 'min',
				'maxDate' => 'max',
			) as $setting => $attribute ) {
				$bound                    = (string) $this->setting( $element, $setting, '' );
				$attributes[ $attribute ] = $this->valid_temporal( $bound, $type ) ? $bound : null;
			}
			$step               = $this->setting( $element, 'timeStep' );
			$attributes['step'] = 'date' !== $type && is_numeric( $step ) && (float) $step > 0 ? (string) $step : null;
		}
		if ( 'hidden' === $type ) {
			return '<div class="swf-field swf-field--hidden-input"' . $this->attrs(
				array(
					'data-swf-id'   => $id,
					'data-swf-path' => $context->path( $id ),
					'hidden'        => true,
				)
			) . '><input' . $this->attrs( $attributes ) . '></div>';
		}
		$control = '<input' . $this->attrs( array_merge( $attributes, $this->aria( $element, $context ) ) ) . '>';
		return $this->wrap( $element, $context, $control );
	}
}
