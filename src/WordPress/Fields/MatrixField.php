<?php
/**
 * Single-answer matrix, including Likert scales, with stable row keys.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class MatrixField extends AbstractFieldType {

	public function type(): string {
		return 'matrix';
	}

	public function label(): string {
		return __( 'Matrix / Likert', 'sw-forms' );
	}

	public function icon(): string {
		return 'r_grid_on';
	}

	public function category(): string {
		return 'choice';
	}

	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'rows',
				'type'    => 'options',
				'tab'     => 'general',
				'label'   => __( 'Questions (rows)', 'sw-forms' ),
				'help'    => __( 'Up to 50 questions. Use stable values starting with a letter, followed by letters, digits, underscores or hyphens (max. 64 characters). Changing a value changes the stored answer key.', 'sw-forms' ),
				'images'  => false,
				'default' => array(
					array(
						'label' => __( 'Question 1', 'sw-forms' ),
						'value' => 'question-1',
					),
					array(
						'label' => __( 'Question 2', 'sw-forms' ),
						'value' => 'question-2',
					),
				),
			),
			array(
				'name'    => 'options',
				'type'    => 'options',
				'tab'     => 'general',
				'label'   => __( 'Answers (columns)', 'sw-forms' ),
				'help'    => __( 'Up to 20 answers, one per question. The default is a five-point Likert scale; labels and stored values can be edited. Required matrices need an answer in every row.', 'sw-forms' ),
				'images'  => false,
				'default' => array(
					array(
						'label' => __( 'Strongly disagree', 'sw-forms' ),
						'value' => '1',
					),
					array(
						'label' => __( 'Disagree', 'sw-forms' ),
						'value' => '2',
					),
					array(
						'label' => __( 'Neither agree nor disagree', 'sw-forms' ),
						'value' => '3',
					),
					array(
						'label' => __( 'Agree', 'sw-forms' ),
						'value' => '4',
					),
					array(
						'label' => __( 'Strongly agree', 'sw-forms' ),
						'value' => '5',
					),
				),
			),
		);
	}

	public function normalize_settings( array $settings ): array {
		$settings            = parent::normalize_settings( $settings );
		$defaults            = $this->default_settings();
		$rows                = array_filter( $settings['rows'], static fn ( array $row ): bool => 1 === preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $row['value'] ) );
		$settings['rows']    = array_slice( array_values( $rows ), 0, 50 );
		$settings['options'] = array_slice( $settings['options'], 0, 20 );
		foreach ( array( 'rows', 'options' ) as $name ) {
			if ( array() === $settings[ $name ] ) {
				$settings[ $name ] = $defaults[ $name ];
			}
		}
		return $settings;
	}

	public function default_value( array $element ): mixed {
		return array();
	}

	public function value_kind( array $element ): string {
		return 'matrix';
	}

	public function collect( array $raw, array $element ): mixed {
		$raw = $raw[ $element['id'] ] ?? array();
		$out = array();
		foreach ( $this->rows( $element ) as $key => $label ) {
			$value = is_array( $raw ) ? ( $raw[ $key ] ?? '' ) : null;
			if ( is_scalar( $value ) ) {
				$value = sanitize_text_field( (string) $value );
			}
			if ( '' !== $value ) {
				// Keep malformed non-scalar answers so validation rejects them, even on optional matrices.
				$out[ $key ] = is_string( $value ) ? $value : null;
			}
		}
		return $out;
	}

	public function validate( mixed $value, array $element ): array {
		$options = $this->options( $element );
		$rows    = $this->rows( $element );
		foreach ( is_array( $value ) ? $value : array() as $key => $answer ) {
			if ( ! array_key_exists( $key, $rows ) || ! is_string( $answer ) || ! array_key_exists( $answer, $options ) ) {
				return array( array( 'code' => 'option' ) );
			}
		}
		if ( ! empty( $element['required'] ) && count( $rows ) !== count( (array) $value ) ) {
			return array( array( 'code' => 'matrix_complete' ) );
		}
		return array();
	}

	public function format_text( mixed $value, array $element ): string {
		$out     = array();
		$options = $this->options( $element );
		foreach ( $this->rows( $element ) as $key => $label ) {
			$answer = is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
			if ( is_string( $answer ) && '' !== $answer ) {
				$out[] = $label . ': ' . ( $options[ $answer ] ?? $answer );
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array<string, string>
	 */
	private function rows( array $element ): array {
		$rows = array();
		foreach ( (array) $this->setting( $element, 'rows', array() ) as $row ) {
			$rows[ $row['value'] ] = $row['label'];
		}
		return $rows;
	}

	public function render( array $element, RenderContext $context ): string {
		$id      = (string) $element['id'];
		$dom_id  = $context->dom_id( $id );
		$value   = $context->value( $id );
		$options = $this->options( $element );
		$html    = '<div class="swf-matrix-scroll" role="region" tabindex="0" aria-label="' . esc_attr( (string) ( $element['label'] ?? $this->label() ) ) . '"><table class="swf-matrix"><thead><tr><td></td>';
		$index   = 0;
		foreach ( $options as $label ) {
			$html .= '<th scope="col" id="' . esc_attr( $dom_id . '-col-' . ( $index++ ) ) . '">' . esc_html( $label ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		$index = 0;
		foreach ( $this->rows( $element ) as $key => $label ) {
			$row_id = $dom_id . '-row-' . ( $index++ );
			$html  .= '<tr><th scope="row" id="' . esc_attr( $row_id ) . '">' . esc_html( $label ) . '</th>';
			$column = 0;
			foreach ( $options as $option => $unused ) {
				$html .= '<td><input' . $this->attrs(
					array(
						'type'                => 'radio',
						'id'                  => $row_id . '-answer-' . $column,
						'name'                => $context->name( $id, '[' . $key . ']' ),
						'value'               => (string) $option,
						'checked'             => is_array( $value ) && ( $value[ $key ] ?? null ) === (string) $option,
						'data-swf-matrix-row' => $key,
						'aria-labelledby'     => $row_id . ' ' . $dom_id . '-col-' . ( $column++ ),
					) + $this->aria( $element, $context )
				) . '></td>';
			}
			$html .= '</tr>';
		}
		// Preserve an unanswered matrix (and its repeater row) in native form submissions.
		$presence = '<input' . $this->attrs(
			array(
				'type'  => 'hidden',
				'name'  => $context->name( $id, '[__empty]' ),
				'value' => '',
			)
		) . '>';
		return $this->wrap( $element, $context, $presence . $html . '</tbody></table></div>', true );
	}
}
