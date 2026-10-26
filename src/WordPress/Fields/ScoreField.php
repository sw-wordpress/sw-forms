<?php
/**
 * Shared native radio implementation for ratings and numeric scales.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

abstract class ScoreField extends RangeField {
	public function category(): string {
		return 'choice';
	}

	public function value_kind( array $element ): string {
		return 'radio';
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'min',
				'type'    => 'number',
				'integer' => true,
				'min'     => 'rating' === $this->type() ? 1 : 0,
				'max'     => 'rating' === $this->type() ? 1 : 19,
				'tab'     => 'validation',
				'label'   => __( 'Minimum', 'sw-forms' ),
				'default' => 1,
			),
			array(
				'name'    => 'max',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'max'     => 'rating' === $this->type() ? 10 : 20,
				'tab'     => 'validation',
				'label'   => __( 'Maximum', 'sw-forms' ),
				'default' => 'rating' === $this->type() ? 5 : 10,
			),
			array(
				'name'    => 'default',
				'type'    => 'number',
				'integer' => true,
				'tab'     => 'general',
				'label'   => __( 'Default value', 'sw-forms' ),
				'default' => '',
			),
		);
	}

	public function render( array $element, RenderContext $context ): string {
		$id                = (string) $element['id'];
		list( $min, $max ) = $this->bounds( $element );
		$controls          = '';
		for ( $score = (int) $min; $score <= (int) $max; ++$score ) {
			$dom_id = $context->dom_id( $id ) . '-' . $score;
			$label  = (string) $score;
			if ( 'rating' === $this->type() ) {
				/* translators: 1: rating value, 2: maximum rating */
				$label = '<span aria-hidden="true">★</span><span class="swf-sr">' . esc_html( sprintf( __( '%1$d of %2$d', 'sw-forms' ), $score, (int) $max ) ) . '</span>';
			}
			$controls .= '<div class="swf-choice"><input' . $this->attrs(
				array_merge(
					$this->aria( $element, $context ),
					array(
						'type'    => 'radio',
						'id'      => $dom_id,
						'name'    => $context->name( $id ),
						'value'   => (string) $score,
						'checked' => (string) $context->value( $id ) === (string) $score,
					)
				)
			) . '><label for="' . esc_attr( $dom_id ) . '">' . $label . '</label></div>';
		}
		return $this->wrap( $element, $context, '<div class="swf-choices swf-choices--horizontal swf-choices--buttons swf-score--' . esc_attr( $this->type() ) . '">' . $controls . '</div>', true );
	}
}
