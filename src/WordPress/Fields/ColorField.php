<?php
/**
 * Native RGB color picker.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class ColorField extends AbstractFieldType {
	public function type(): string {
		return 'color';
	}

	public function label(): string {
		return __( 'Color', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_palette';
	}

	public function category(): string {
		return 'basic';
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'default',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Default color (#RRGGBB)', 'sw-forms' ),
				'default' => '#000000',
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings            = parent::normalize_settings( $settings );
		$settings['default'] = 1 === preg_match( '/^#[0-9a-f]{6}$/i', $settings['default'] ) ? strtolower( $settings['default'] ) : '#000000';
		return $settings;
	}

	public function default_value( array $element ): mixed {
		return (string) $this->setting( $element, 'default', '#000000' );
	}

	public function collect( array $raw, array $element ): mixed {
		return strtolower( (string) parent::collect( $raw, $element ) );
	}

	public function validate( mixed $value, array $element ): array {
		return is_string( $value ) && 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? array() : array( array( 'code' => 'color' ) );
	}

	public function render( array $element, RenderContext $context ): string {
		$id    = (string) $element['id'];
		$value = $context->value( $id );
		$value = is_string( $value ) && array() === $this->validate( $value, $element ) ? $value : $this->default_value( $element );
		return $this->wrap(
			$element,
			$context,
			'<input' . $this->attrs(
				array_merge(
					$this->aria( $element, $context ),
					array(
						'type'  => 'color',
						'id'    => $context->dom_id( $id ),
						'name'  => $context->name( $id ),
						'value' => (string) $value,
						'class' => 'swf-color',
					)
				)
			) . '>'
		);
	}
}
