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

	public const INPUT_TYPES = array( 'text', 'email', 'tel', 'url', 'number', 'date', 'password', 'hidden' );

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
				if ( ! Rules::date( $value ) ) {
					return array( array( 'code' => 'date' ) );
				}
				break;
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
		return array();
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
			'type'         => 'number' === $type ? 'text' : $type,
			'inputmode'    => 'number' === $type ? 'decimal' : null,
			'id'           => $context->dom_id( $id ),
			'name'         => $context->name( $id ),
			'value'        => $value,
			'class'        => 'swf-input',
			'placeholder'  => (string) $this->setting( $element, 'placeholder', '' ) ?: null,
			'autocomplete' => (string) $this->setting( $element, 'autocomplete', '' ) ?: null,
			'maxlength'    => is_int( $this->setting( $element, 'maxLength' ) ) ? (int) $this->setting( $element, 'maxLength' ) : null,
		);
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
