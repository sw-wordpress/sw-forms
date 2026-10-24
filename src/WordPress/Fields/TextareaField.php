<?php
/**
 * Multi line text.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Validation\Rules;
use SWForms\WordPress\Frontend\RenderContext;

final class TextareaField extends AbstractFieldType {

	public function type(): string {
		return 'textarea';
	}

	public function label(): string {
		return __( 'Text area', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_notes';
	}

	public function category(): string {
		return 'basic';
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'placeholder',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Placeholder', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'default',
				'type'    => 'textarea',
				'tab'     => 'general',
				'label'   => __( 'Default value', 'sw-forms' ),
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
			),
			array(
				'name'    => 'maxLength',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'tab'     => 'validation',
				'label'   => __( 'Maximum length', 'sw-forms' ),
				'default' => null,
			),
			array(
				'name'    => 'rows',
				'type'    => 'number',
				'integer' => true,
				'min'     => 2,
				'max'     => 30,
				'tab'     => 'appearance',
				'label'   => __( 'Visible rows', 'sw-forms' ),
				'default' => 5,
			),
		);
	}

	public function collect( array $raw, array $element ): mixed {
		$value = $raw[ $element['id'] ] ?? '';
		return is_scalar( $value ) ? $this->limit( sanitize_textarea_field( (string) $value ), 65000 ) : '';
	}

	public function validate( mixed $value, array $element ): array {
		$length = Rules::length( (string) $value );
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
		return array();
	}

	public function render( array $element, RenderContext $context ): string {
		$id      = (string) $element['id'];
		$value   = $context->value( $id );
		$max     = $this->setting( $element, 'maxLength' );
		$control = '<textarea' . $this->attrs(
			array_merge(
				array(
					'id'          => $context->dom_id( $id ),
					'name'        => $context->name( $id ),
					'class'       => 'swf-textarea',
					'rows'        => (int) $this->setting( $element, 'rows', 5 ),
					'placeholder' => (string) $this->setting( $element, 'placeholder', '' ) ?: null,
					'maxlength'   => is_int( $max ) ? $max : null,
				),
				$this->aria( $element, $context )
			)
		) . '>' . esc_textarea( is_scalar( $value ) ? (string) $value : '' ) . '</textarea>';
		return $this->wrap( $element, $context, $control );
	}
}
