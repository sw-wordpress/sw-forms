<?php
/**
 * Layout container with 1-6 columns. Produces no data; each column may carry its own logic.
 *
 * Column widths are units of a 12 column grid and are rendered as `fr` tracks.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Form\TypeCatalog;
use SWForms\WordPress\Frontend\RenderContext;

final class ColumnsField extends AbstractFieldType {

	public function type(): string {
		return 'columns';
	}

	public function label(): string {
		return __( 'Columns', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_view_column';
	}

	public function category(): string {
		return 'layout';
	}

	public function is_data(): bool {
		return false;
	}

	public function container(): string {
		return TypeCatalog::CONTAINER_COLUMNS;
	}

	public function supports(): array {
		return array( 'cssClass', 'adminLabel', 'logic' );
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'gap',
				'type'    => 'select',
				'tab'     => 'appearance',
				'label'   => __( 'Gap between columns', 'sw-forms' ),
				'default' => 'md',
				'choices' => array(
					array(
						'value' => 'sm',
						'label' => __( 'Small', 'sw-forms' ),
					),
					array(
						'value' => 'md',
						'label' => __( 'Medium', 'sw-forms' ),
					),
					array(
						'value' => 'lg',
						'label' => __( 'Large', 'sw-forms' ),
					),
				),
			),
			array(
				'name'    => 'stack',
				'type'    => 'select',
				'tab'     => 'appearance',
				'label'   => __( 'Stack columns on narrow screens', 'sw-forms' ),
				'default' => 'md',
				'choices' => array(
					array(
						'value' => 'sm',
						'label' => __( 'Below 480 px', 'sw-forms' ),
					),
					array(
						'value' => 'md',
						'label' => __( 'Below 720 px', 'sw-forms' ),
					),
					array(
						'value' => 'never',
						'label' => __( 'Never', 'sw-forms' ),
					),
				),
			),
			array(
				'name'    => 'reverseOnStack',
				'type'    => 'toggle',
				'tab'     => 'appearance',
				'label'   => __( 'Reverse order when stacked', 'sw-forms' ),
				'default' => false,
			),
		);
	}

	public function default_value( array $element ): mixed {
		return null;
	}

	public function collect( array $raw, array $element ): mixed {
		return null;
	}

	public function value_kind( array $element ): string {
		return 'none';
	}

	public function render( array $element, RenderContext $context ): string {
		$id      = (string) $element['id'];
		$columns = is_array( $element['columns'] ?? null ) ? $element['columns'] : array();
		$tracks  = array();
		$html    = '';
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) ) {
				continue;
			}
			$column_id = (string) ( $column['id'] ?? '' );
			$tracks[]  = max( 1, min( 12, (int) ( $column['width'] ?? 6 ) ) ) . 'fr';
			$child_ids = array_values( array_filter( array_map( static fn ( $child ): string => is_array( $child ) ? (string) ( $child['id'] ?? '' ) : '', is_array( $column['children'] ?? null ) ? $column['children'] : array() ) ) );
			$html     .= '<div' . $this->attrs(
				array(
					'class'         => 'swf-col',
					'data-swf-id'   => $column_id,
					'data-swf-path' => $context->path( $column_id ),
					'hidden'        => $context->hidden( $column_id ),
				)
			) . '>' . $context->children( $child_ids ) . '</div>';
		}
		$classes = array(
			'swf-columns',
			'swf-columns--gap-' . (string) $this->setting( $element, 'gap', 'md' ),
			'swf-columns--stack-' . (string) $this->setting( $element, 'stack', 'md' ),
		);
		if ( $this->setting( $element, 'reverseOnStack', false ) ) {
			$classes[] = 'swf-columns--reverse';
		}
		$css_class = (string) ( $element['cssClass'] ?? '' );
		if ( '' !== $css_class ) {
			$classes[] = $css_class;
		}
		return '<div' . $this->attrs(
			array(
				'class'         => implode( ' ', $classes ),
				'style'         => '--swf-tracks:' . implode( ' ', $tracks ),
				'data-swf-id'   => $id,
				'data-swf-path' => $context->path( $id ),
				'hidden'        => $context->hidden( $id ),
			)
		) . '>' . $html . '</div>';
	}
}
