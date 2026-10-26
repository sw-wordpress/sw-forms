<?php
/**
 * Group of elements, optionally repeatable (repeater). Value: object, or list of objects.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Form\TypeCatalog;
use SWForms\WordPress\Frontend\RenderContext;

class GroupField extends AbstractFieldType {

	public const MAX_ROWS = 50;

	public function type(): string {
		return 'group';
	}

	public function label(): string {
		return __( 'Group', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_folder_open';
	}

	public function category(): string {
		return 'layout';
	}

	public function container(): string {
		return TypeCatalog::CONTAINER_CHILDREN;
	}

	public function supports(): array {
		return array( 'key', 'label', 'description', 'cssClass', 'adminLabel', 'logic' );
	}

	public function settings_schema(): array {
		$repeatable = array(
			'setting' => 'repeatable',
			'equals'  => array( true ),
		);
		return array(
			array(
				'name'    => 'repeatable',
				'type'    => 'toggle',
				'tab'     => 'general',
				'label'   => __( 'Repeatable (visitors can add rows)', 'sw-forms' ),
				'default' => false,
			),
			array(
				'name'    => 'minRows',
				'type'    => 'number',
				'integer' => true,
				'min'     => 0,
				'max'     => self::MAX_ROWS,
				'tab'     => 'validation',
				'label'   => __( 'Minimum rows', 'sw-forms' ),
				'default' => 1,
				'showIf'  => $repeatable,
			),
			array(
				'name'    => 'maxRows',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'max'     => self::MAX_ROWS,
				'tab'     => 'validation',
				'label'   => __( 'Maximum rows', 'sw-forms' ),
				'default' => 10,
				'showIf'  => $repeatable,
			),
			array(
				'name'    => 'addLabel',
				'type'    => 'text',
				'tab'     => 'appearance',
				'label'   => __( 'Text of the "add" button', 'sw-forms' ),
				'default' => __( 'Add entry', 'sw-forms' ),
				'showIf'  => $repeatable,
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings = parent::normalize_settings( $settings );
		if ( (int) $settings['maxRows'] < (int) $settings['minRows'] ) {
			$settings['maxRows'] = max( 1, (int) $settings['minRows'] );
		}
		return $settings;
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

	/**
	 * @param array<string, mixed> $element
	 * @return array{0: int, 1: int} Minimum and maximum rows.
	 */
	public static function row_limits( array $element ): array {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		$min      = max( 0, (int) ( $settings['minRows'] ?? 1 ) );
		$max      = min( self::MAX_ROWS, max( 1, (int) ( $settings['maxRows'] ?? 10 ) ) );
		return array( $min, max( $min, $max ) );
	}

	public function render( array $element, RenderContext $context ): string {
		$id        = (string) $element['id'];
		$children  = is_array( $element['children'] ?? null ) ? $element['children'] : array();
		$child_ids = array_values( array_filter( array_map( static fn ( $child ): string => is_array( $child ) ? (string) ( $child['id'] ?? '' ) : '', $children ) ) );

		if ( empty( $element['settings']['repeatable'] ) ) {
			return $this->wrap( $element, $context, '<div class="swf-group__body">' . $context->children( $child_ids ) . '</div>', true );
		}

		list( $min, $max ) = self::row_limits( $element );
		$rows              = $context->value( $id );
		$rows              = is_array( $rows ) ? array_values( $rows ) : array();
		$label             = (string) ( $element['label'] ?? '' );
		$html              = '<div class="swf-rows">';
		foreach ( $rows as $index => $row ) {
			$html .= $this->row( $context->row( $id, (string) $index, is_array( $row ) ? $row : array() ), $child_ids, $index + 1, $label );
		}
		$html .= '</div>';
		$html .= '<template class="swf-row-template">' . $this->row( $context->row( $id, '__INDEX__', array(), true ), $child_ids, 0, $label ) . '</template>';
		$html .= '<button type="button" class="swf-button swf-row-add"' . ( count( $rows ) >= $max ? ' hidden' : '' ) . '>'
			. esc_html( (string) ( $element['settings']['addLabel'] ?? '' ) ) . '</button>';

		$element['cssClass'] = trim( 'swf-repeater ' . (string) ( $element['cssClass'] ?? '' ) );
		return $this->wrap(
			$element,
			$context,
			$html,
			true,
			array(
				'data-swf-min' => $min,
				'data-swf-max' => $max,
			)
		);
	}

	/**
	 * @param list<string> $child_ids
	 */
	private function row( RenderContext $row_context, array $child_ids, int $number, string $label ): string {
		$remove_label = sprintf(
			/* translators: 1: group label, 2: row number */
			__( 'Remove %1$s %2$s', 'sw-forms' ),
			$label,
			0 === $number ? '' : (string) $number
		);
		return '<div class="swf-row" data-swf-row="' . esc_attr( $row_context->row_index() ) . '">'
			. '<div class="swf-row__fields">' . $row_context->children( $child_ids ) . '</div>'
			. '<button type="button" class="swf-row-remove" aria-label="' . esc_attr( trim( $remove_label ) ) . '"><span aria-hidden="true">&times;</span></button>'
			. '</div>';
	}
}
