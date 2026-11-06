<?php
/**
 * Server-derived numeric value. Client values are never trusted.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class CalculationField extends AbstractFieldType {
	public function type(): string {
		return 'calculation'; }
	public function label(): string {
		return __( 'Calculation', 'sw-forms' ); }
	public function icon(): string {
		return 'r_calculate'; }
	public function category(): string {
		return 'basic'; }
	public function supports(): array {
		return array( 'key', 'label', 'description', 'cssClass', 'adminLabel' ); }
	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'operation',
				'type'    => 'select',
				'label'   => __( 'Operation', 'sw-forms' ),
				'default' => 'sum',
				'choices' => array(
					array(
						'value' => 'sum',
						'label' => __( 'Sum', 'sw-forms' ),
					),
					array(
						'value' => 'product',
						'label' => __( 'Product', 'sw-forms' ),
					),
					array(
						'value' => 'difference',
						'label' => __( 'Difference', 'sw-forms' ),
					),
					array(
						'value' => 'quotient',
						'label' => __( 'Quotient', 'sw-forms' ),
					),
				),
			),
			array(
				'name'     => 'sources',
				'type'     => 'repeater',
				'label'    => __( 'Source fields (in order)', 'sw-forms' ),
				'default'  => array(),
				'maxItems' => 20,
				'fields'   => array(
					array(
						'name'       => 'field',
						'type'       => 'field-picker',
						'label'      => __( 'Field', 'sw-forms' ),
						'default'    => '',
						'fieldTypes' => array( 'input', 'range', 'rating', 'scale' ),
					),
				),
			),
			array(
				'name'    => 'decimals',
				'type'    => 'number',
				'label'   => __( 'Decimal places', 'sw-forms' ),
				'default' => 2,
				'min'     => 0,
				'max'     => 8,
				'integer' => true,
			),
		);
	}
	public function is_empty( mixed $value, array $element ): bool {
		return false; }
	public function validate( mixed $value, array $element ): array {
		return '' === $value ? array( array( 'code' => 'calculation_result' ) ) : array(); }
	public function collect( array $raw, array $element ): mixed {
		return ''; }
	public function render( array $element, RenderContext $context ): string {
		return $this->wrap(
			$element,
			$context,
			'<input' . $this->attrs(
				array_merge(
					$this->aria( $element, $context ),
					array(
						'id'       => $context->dom_id( $element['id'] ),
						'name'     => $context->name( $element['id'] ),
						'type'     => 'text',
						'readonly' => true,
						'value'    => (string) $context->value( $element['id'] ),
					)
				)
			) . '>'
		);
	}
}
