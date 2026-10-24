<?php
/**
 * Shared settings and rendering of checkbox and radio lists.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

abstract class ChoiceField extends AbstractFieldType {

	public function category(): string {
		return 'choice';
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function options_setting( ?array $show_if = null ): array {
		return array(
			'name'    => 'options',
			'type'    => 'options',
			'tab'     => 'general',
			'label'   => __( 'Options', 'sw-forms' ),
			'default' => array(
				array(
					'label' => __( 'Option 1', 'sw-forms' ),
					'value' => 'option-1',
				),
				array(
					'label' => __( 'Option 2', 'sw-forms' ),
					'value' => 'option-2',
				),
			),
			'showIf'  => $show_if,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function layout_setting( ?array $show_if = null ): array {
		return array(
			'name'    => 'layout',
			'type'    => 'select',
			'tab'     => 'appearance',
			'label'   => __( 'Arrangement', 'sw-forms' ),
			'default' => 'vertical',
			'choices' => array(
				array(
					'value' => 'vertical',
					'label' => __( 'One below the other', 'sw-forms' ),
				),
				array(
					'value' => 'horizontal',
					'label' => __( 'Side by side', 'sw-forms' ),
				),
			),
			'showIf'  => $show_if,
		);
	}

	/**
	 * One checkbox or radio control with its label.
	 *
	 * @param array<string, string|bool|null> $attributes
	 */
	protected function choice( array $attributes, string $label_html ): string {
		return '<div class="swf-choice"><input' . $this->attrs( $attributes ) . '><label for="' . esc_attr( (string) $attributes['id'] ) . '">' . $label_html . '</label></div>';
	}

	/**
	 * @param array<string, mixed> $element
	 */
	protected function list_class( array $element ): string {
		return 'swf-choices swf-choices--' . ( 'horizontal' === $this->setting( $element, 'layout' ) ? 'horizontal' : 'vertical' );
	}

	/**
	 * @param array<string, mixed> $element
	 */
	protected function invalid_attr( array $element, RenderContext $context ): ?string {
		return '' !== $context->error( (string) $element['id'] ) ? 'true' : null;
	}
}
