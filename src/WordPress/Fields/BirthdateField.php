<?php
/**
 * Birthdate with optional completed-age limits.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Validation\Birthdate;
use SWForms\Core\Validation\Rules;
use SWForms\WordPress\Frontend\RenderContext;

final class BirthdateField extends AbstractFieldType {
	public function type(): string {
		return 'birthdate';
	}

	public function label(): string {
		return __( 'Birthdate', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_cake';
	}

	public function category(): string {
		return 'basic';
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'validate',
				'type'    => 'toggle',
				'tab'     => 'validation',
				'label'   => __( 'Validate value', 'sw-forms' ),
				'default' => true,
			),
			array(
				'name'    => 'minAge',
				'type'    => 'number',
				'integer' => true,
				'min'     => 0,
				'max'     => 150,
				'tab'     => 'validation',
				'label'   => __( 'Minimum age', 'sw-forms' ),
				'default' => null,
			),
			array(
				'name'    => 'maxAge',
				'type'    => 'number',
				'integer' => true,
				'min'     => 0,
				'max'     => 150,
				'tab'     => 'validation',
				'label'   => __( 'Maximum age', 'sw-forms' ),
				'default' => null,
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings = parent::normalize_settings( $settings );
		if ( null !== $settings['minAge'] && null !== $settings['maxAge'] ) {
			$settings['maxAge'] = max( $settings['minAge'], $settings['maxAge'] );
		}
		return $settings;
	}

	public function validate( mixed $value, array $element ): array {
		if ( ! $this->setting( $element, 'validate', true ) ) {
			return array();
		}
		if ( ! is_string( $value ) || ! Rules::date( $value ) ) {
			return array( array( 'code' => 'date' ) );
		}
		$age = Birthdate::age( $value, current_datetime() );
		if ( null === $age ) {
			return array( array( 'code' => 'birthdate' ) );
		}
		$min = $this->setting( $element, 'minAge' );
		$max = $this->setting( $element, 'maxAge' );
		if ( is_int( $min ) && $age < $min ) {
			return array(
				array(
					'code'   => 'min_age',
					'params' => array( 'min' => $min ),
				),
			);
		}
		if ( is_int( $max ) && $age > $max ) {
			return array(
				array(
					'code'   => 'max_age',
					'params' => array( 'max' => $max ),
				),
			);
		}
		return array();
	}

	public function render( array $element, RenderContext $context ): string {
		$id       = (string) $element['id'];
		$validate = (bool) $this->setting( $element, 'validate', true );
		$bounds   = Birthdate::bounds( current_datetime(), $this->setting( $element, 'minAge' ), $this->setting( $element, 'maxAge' ) );
		$value    = $context->value( $id );
		$control  = '<input' . $this->attrs(
			array_merge(
				$this->aria( $element, $context ),
				array(
					'type'         => $validate ? 'date' : 'text',
					'id'           => $context->dom_id( $id ),
					'name'         => $context->name( $id ),
					'class'        => 'swf-input',
					'value'        => is_string( $value ) ? $value : '',
					'autocomplete' => 'bday',
					'min'          => $validate && '' !== $bounds['min'] ? $bounds['min'] : null,
					'max'          => $validate ? $bounds['max'] : null,
				)
			)
		) . '>';
		return $this->wrap( $element, $context, $control );
	}
}
