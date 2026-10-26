<?php
/**
 * Numeric slider with server-side limits.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Validation\Rules;
use SWForms\WordPress\Frontend\RenderContext;

class RangeField extends AbstractFieldType {
	public function type(): string {
		return 'range';
	}

	public function label(): string {
		return __( 'Number range', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_tune';
	}

	public function category(): string {
		return 'basic';
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'min',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Minimum', 'sw-forms' ),
				'min'     => -1000000000,
				'max'     => 1000000000,
				'default' => 0,
			),
			array(
				'name'    => 'max',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Maximum', 'sw-forms' ),
				'min'     => -1000000000,
				'max'     => 1000000000,
				'default' => 100,
			),
			array(
				'name'    => 'step',
				'type'    => 'number',
				'tab'     => 'validation',
				'label'   => __( 'Step', 'sw-forms' ),
				'min'     => 0.000001,
				'max'     => 1000000000,
				'default' => 1,
			),
			array(
				'name'    => 'default',
				'type'    => 'number',
				'tab'     => 'general',
				'label'   => __( 'Default value', 'sw-forms' ),
				'default' => 0,
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings = parent::normalize_settings( $settings );
		foreach ( array( 'min', 'max', 'step' ) as $key ) {
			if ( isset( $settings[ $key ] ) && ! is_finite( (float) $settings[ $key ] ) ) {
				$settings[ $key ] = $this->default_settings()[ $key ];
			}
		}
		$settings['max'] = max( $settings['min'], $settings['max'] );
		if ( '' !== ( $settings['default'] ?? '' ) ) {
			$min                 = (float) $settings['min'];
			$max                 = (float) $settings['max'];
			$step                = max( 0.000001, (float) ( $settings['step'] ?? 1 ) );
			$value               = is_numeric( $settings['default'] ) && is_finite( (float) $settings['default'] ) ? (float) $settings['default'] : $min;
			$settings['default'] = $min + max( 0, floor( ( min( $max, max( $min, $value ) ) - $min ) / $step + 1e-9 ) ) * $step;
		}
		return $settings;
	}

	/** @param array<string, mixed> $element
	 * @return array{float, float, float}
	 */
	protected function bounds( array $element ): array {
		$defaults = $this->default_settings();
		return array( (float) $this->setting( $element, 'min', $defaults['min'] ), (float) $this->setting( $element, 'max', $defaults['max'] ), (float) $this->setting( $element, 'step', 1 ) );
	}

	public function default_value( array $element ): mixed {
		return (string) $this->setting( $element, 'default', $this->default_settings()['default'] );
	}

	public function collect( array $raw, array $element ): mixed {
		$value  = (string) parent::collect( $raw, $element );
		$number = Rules::number( $value );
		return null !== $number && is_finite( $number ) ? (string) $number : $value;
	}

	public function validate( mixed $value, array $element ): array {
		$number = is_scalar( $value ) ? Rules::number( (string) $value ) : null;
		if ( null === $number || ! is_finite( $number ) ) {
			return array( array( 'code' => 'number' ) );
		}
		list( $min, $max, $step ) = $this->bounds( $element );
		if ( $number < $min ) {
			return array(
				array(
					'code'   => 'min',
					'params' => array( 'min' => (string) $min ),
				),
			);
		}
		if ( $number > $max ) {
			return array(
				array(
					'code'   => 'max',
					'params' => array( 'max' => (string) $max ),
				),
			);
		}
		return Rules::step( $number, $step, $min ) ? array() : array( array( 'code' => 'step' ) );
	}

	public function render( array $element, RenderContext $context ): string {
		$id                       = (string) $element['id'];
		list( $min, $max, $step ) = $this->bounds( $element );
		$value                    = $context->value( $id );
		$value                    = is_scalar( $value ) && '' !== (string) $value ? (string) $value : (string) $this->default_value( $element );
		$control                  = '<input' . $this->attrs(
			array_merge(
				$this->aria( $element, $context ),
				array(
					'type'  => 'range',
					'id'    => $context->dom_id( $id ),
					'name'  => $context->name( $id ),
					'class' => 'swf-range',
					'min'   => (string) $min,
					'max'   => (string) $max,
					'step'  => (string) $step,
					'value' => $value,
				)
			)
		) . '><output hidden for="' . esc_attr( $context->dom_id( $id ) ) . '" class="swf-range-value">' . esc_html( $value ) . '</output>';
		return $this->wrap( $element, $context, $control );
	}
}
