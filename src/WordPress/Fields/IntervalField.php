<?php
/**
 * Complete, ordered temporal pairs, stored as [from, to].
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Validation\TemporalBound;
use SWForms\Core\Validation\TemporalInterval;
use SWForms\WordPress\Frontend\RenderContext;

abstract class IntervalField extends AbstractFieldType {
	abstract protected function input_type(): string;
	public function category(): string {
		return 'basic';
	}

	public function value_kind( array $element ): string {
		return 'list';
	}

	public function settings_schema(): array {
		$schema = array(
			array(
				'name'    => 'defaultFrom',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Default start', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'defaultTo',
				'type'    => 'text',
				'tab'     => 'general',
				'label'   => __( 'Default end', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'validate',
				'type'    => 'toggle',
				'tab'     => 'validation',
				'label'   => __( 'Validate value', 'sw-forms' ),
				'default' => true,
			),
			array(
				'name'    => 'allowEqual',
				'type'    => 'toggle',
				'tab'     => 'validation',
				'label'   => __( 'Allow equal endpoints', 'sw-forms' ),
				'default' => true,
			),
			array(
				'name'    => 'min',
				'type'    => 'text',
				'tab'     => 'validation',
				'label'   => __( 'Earliest value', 'sw-forms' ),
				'help'    => __( 'ISO date or time. Dates also support today with an offset, e.g. today + 7 days.', 'sw-forms' ),
				'default' => '',
			),
			array(
				'name'    => 'max',
				'type'    => 'text',
				'tab'     => 'validation',
				'label'   => __( 'Latest value', 'sw-forms' ),
				'default' => '',
			),
		);
		if ( 'time' === $this->input_type() ) {
			$schema[] = array(
				'name'    => 'step',
				'type'    => 'number',
				'integer' => true,
				'min'     => 1,
				'max'     => 86400,
				'tab'     => 'validation',
				'label'   => __( 'Time interval (seconds)', 'sw-forms' ),
				'default' => 60,
			);
		}
		return $schema;
	}

	public function default_value( array $element ): mixed {
		return TemporalInterval::parse( array( $this->setting( $element, 'defaultFrom', '' ), $this->setting( $element, 'defaultTo', '' ) ) );
	}

	public function collect( array $raw, array $element ): mixed {
		return TemporalInterval::parse( $raw[ $element['id'] ] ?? null );
	}

	public function validate( mixed $value, array $element ): array {
		if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_string( $value[0] ?? null ) || ! is_string( $value[1] ?? null ) || '' === $value[0] || '' === $value[1] ) {
			return array( array( 'code' => 'interval_complete' ) );
		}
		if ( ! $this->setting( $element, 'validate', true ) ) {
			return array();
		}
		$bounds  = $this->bounds( $element );
		$problem = TemporalInterval::problem( $value, $this->input_type(), $bounds['min'], $bounds['max'], (bool) $this->setting( $element, 'allowEqual', true ), 'time' === $this->input_type() ? (int) $this->setting( $element, 'step', 60 ) : null );
		return null === $problem ? array() : array(
			array(
				'code'   => $problem,
				'params' => array(
					'min'        => $bounds['min'],
					'max'        => $bounds['max'],
					'allowEqual' => $this->setting( $element, 'allowEqual', true ) ? '1' : '',
				),
			),
		);
	}
	/** @param array<string, mixed> $element
	 * @return array{min: string, max: string}
	 */
	private function bounds( array $element ): array {
		$now    = current_datetime();
		$bounds = array();
		foreach ( array( 'min', 'max' ) as $key ) {
			$value          = TemporalBound::resolve( (string) $this->setting( $element, $key, '' ), $this->input_type(), $now );
			$bounds[ $key ] = TemporalInterval::valid( $value, $this->input_type() ) ? $value : '';
		}
		return $bounds;
	}

	public function format_text( mixed $value, array $element ): string {
		return is_array( $value ) && 2 === count( $value ) ? implode( ' – ', $value ) : '';
	}

	public function render( array $element, RenderContext $context ): string {
		$id       = (string) $element['id'];
		$value    = $context->value( $id );
		$value    = is_array( $value ) ? $value : array();
		$validate = (bool) $this->setting( $element, 'validate', true );
		$bounds   = $this->bounds( $element );
		$html     = '<div class="swf-interval">';
		foreach ( array(
			'from' => __( 'From', 'sw-forms' ),
			'to'   => __( 'To', 'sw-forms' ),
		) as $part => $label ) {
			$index  = 'from' === $part ? 0 : 1;
			$dom_id = $context->dom_id( $id ) . '-' . $part;
			$html  .= '<div><label for="' . esc_attr( $dom_id ) . '">' . esc_html( $label ) . '</label><input' . $this->attrs(
				array_merge(
					$this->aria( $element, $context ),
					array(
						'type'                 => $validate ? $this->input_type() : 'text',
						'id'                   => $dom_id,
						'name'                 => $context->name( $id, '[' . $part . ']' ),
						'class'                => 'swf-input',
						'data-swf-interval'    => $part,
						'value'                => is_string( $value[ $index ] ?? null ) ? $value[ $index ] : '',
						'min'                  => $validate && '' !== $bounds['min'] ? $bounds['min'] : null,
						'max'                  => $validate && '' !== $bounds['max'] ? $bounds['max'] : null,
						'step'                 => $validate && 'time' === $this->input_type() ? (int) $this->setting( $element, 'step', 60 ) : null,
						'data-swf-time-picker' => $validate && 'time' === $this->input_type(),
						'data-swf-time-labels' => wp_json_encode(
							array(
								'hour'   => __( 'Hours', 'sw-forms' ),
								'minute' => __( 'Minutes', 'sw-forms' ),
								'second' => __( 'Seconds', 'sw-forms' ),
							)
						),
					)
				)
			) . '></div>';
		}
		return $this->wrap( $element, $context, $html . '</div>', true );
	}
}
