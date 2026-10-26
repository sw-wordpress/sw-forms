<?php
/**
 * Plain-text tag list, usable without JavaScript.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Validation\TagValues;
use SWForms\Core\Validation\Rules;
use SWForms\WordPress\Frontend\RenderContext;

final class TagsField extends AbstractFieldType {
	public function type(): string {
		return 'tags';
	}

	public function label(): string {
		return __( 'Tags', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_label';
	}

	public function category(): string {
		return 'basic';
	}

	public function value_kind( array $element ): string {
		return 'list';
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'default',
				'type'    => 'textarea',
				'tab'     => 'general',
				'label'   => __( 'Default tags', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'minTags',
				'type'    => 'number',
				'integer' => true,
				'min'     => 0,
				'max'     => 100,
				'tab'     => 'validation',
				'label'   => __( 'Minimum tags', 'sw-forms' ),
				'default' => 0,
			),
			array(
				'name'    => 'maxTags',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'max'     => 100,
				'tab'     => 'validation',
				'label'   => __( 'Maximum tags', 'sw-forms' ),
				'default' => 20,
			),
			array(
				'name'    => 'maxTagLength',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'max'     => 200,
				'tab'     => 'validation',
				'label'   => __( 'Maximum tag length', 'sw-forms' ),
				'default' => 50,
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings            = parent::normalize_settings( $settings );
		$settings['maxTags'] = max( $settings['minTags'], $settings['maxTags'] );
		return $settings;
	}

	public function default_value( array $element ): mixed {
		return TagValues::parse( $this->setting( $element, 'default', '' ) );
	}

	public function collect( array $raw, array $element ): mixed {
		return TagValues::parse( $raw[ $element['id'] ] ?? '' );
	}

	public function validate( mixed $value, array $element ): array {
		if ( ! is_array( $value ) ) {
			return array( array( 'code' => 'option' ) );
		}
		if ( count( $value ) < (int) $this->setting( $element, 'minTags', 0 ) ) {
			return array(
				array(
					'code'   => 'min_selected',
					'params' => array( 'min' => (int) $this->setting( $element, 'minTags', 0 ) ),
				),
			);
		}
		if ( count( $value ) > (int) $this->setting( $element, 'maxTags', 20 ) ) {
			return array(
				array(
					'code'   => 'max_selected',
					'params' => array( 'max' => (int) $this->setting( $element, 'maxTags', 20 ) ),
				),
			);
		}
		foreach ( $value as $tag ) {
			$max = (int) $this->setting( $element, 'maxTagLength', 50 );
			if ( ! is_string( $tag ) || Rules::length( $tag ) > $max ) {
				return array(
					array(
						'code'   => 'maxlength',
						'params' => array( 'max' => $max ),
					),
				);
			}
		}
		return array();
	}

	public function format_text( mixed $value, array $element ): string {
		return implode( ', ', is_array( $value ) ? $value : array() );
	}

	public function render( array $element, RenderContext $context ): string {
		$id                        = (string) $element['id'];
		$value                     = $context->value( $id );
		$hint                      = $context->dom_id( $id ) . '-tags-hint';
		$aria                      = $this->aria( $element, $context );
		$aria['aria-describedby'] .= ' ' . $hint;
		$control                   = '<textarea' . $this->attrs(
			array_merge(
				$aria,
				array(
					'id'            => $context->dom_id( $id ),
					'name'          => $context->name( $id ),
					'class'         => 'swf-textarea',
					'rows'          => 3,
					'data-swf-tags' => true,
				)
			)
		) . '>' . esc_textarea( implode( "\n", is_array( $value ) ? $value : array() ) ) . '</textarea><p id="' . esc_attr( $hint ) . '" class="swf-description">' . esc_html__( 'Separate tags with commas or line breaks.', 'sw-forms' ) . '</p>';
		return $this->wrap( $element, $context, $control );
	}
}
