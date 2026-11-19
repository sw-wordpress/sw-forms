<?php
/**
 * WPForms Lite/Pro stored form reader. No WPForms services are bootstrapped.
 *
 * @package SWForms
 * @since 0.8.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Import;

final class WPForms implements AdapterInterface {
	public function forms( int $page ): array {
		return Source::forms( 'wpforms', $page );
	}

	public function read( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wpforms' !== $post->post_type || 'trash' === $post->post_status || strlen( $post->post_content ) > 1048576 ) {
			return null;
		}
		$data = json_decode( $post->post_content, true );
		// WPForms' wpforms_decode() unslashes the decoded document, not the JSON string.
		$data = is_array( $data ) ? wp_unslash( $data ) : null;
		if ( ! is_array( $data ) || ! is_array( $data['fields'] ?? null ) || count( $data['fields'] ) > 500 ) {
			return null;
		}
		$result = Source::result( $post->post_title );
		$fields = array();
		foreach ( array_values( $data['fields'] ) as $index => $source ) {
			if ( ! is_array( $source ) ) {
				$result['skipped'][] = '#' . $index;
				continue;
			}
			$type  = is_string( $source['type'] ?? null ) ? $source['type'] : '';
			$label = is_string( $source['label'] ?? null ) ? $source['label'] : '#' . $index;
			$field = $this->convert( $source, $index, $type, $label );
			if ( null === $field ) {
				$result['skipped'][] = $label . ' (' . $type . ')';
				continue;
			}
			$fields[]             = $field;
			$result['imported'][] = $label . ' (' . $type . ') → ' . $field['key'];
		}
		$result['document']['fields'] = $fields;
		$submit                       = $data['settings']['submit_text'] ?? null;
		if ( is_string( $submit ) && '' !== trim( $submit ) ) {
			$result['document']['settings']['submitLabel'] = $submit;
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>|null
	 */
	private function convert( array $source, int $index, string $type, string $label ): ?array {
		$map = array(
			'text'   => 'text',
			'email'  => 'email',
			'number' => 'number',
			'phone'  => 'tel',
			'url'    => 'url',
			'hidden' => 'hidden',
			'name'   => 'text',
		);
		if ( ! isset( $map[ $type ] ) && ! in_array( $type, array( 'textarea', 'select', 'checkbox', 'radio', 'gdpr-checkbox' ), true ) ) {
			return null;
		}
		$settings = array();
		$target   = isset( $map[ $type ] ) ? 'input' : $type;
		if ( 'input' === $target ) {
			$settings['inputType'] = $map[ $type ];
		}
		foreach ( array( 'placeholder', 'default' ) as $name ) {
			$value = $source[ $name . ( 'default' === $name ? '_value' : '' ) ] ?? '';
			// WPForms smart tags must never become literal default values in a new form.
			if ( is_string( $value ) && ! str_contains( $value, '{' ) ) {
				$settings[ $name ] = $value;
			}
		}
		if ( 'gdpr-checkbox' === $type ) {
			$choices = $source['choices'] ?? null;
			if ( ! is_array( $choices ) || 1 !== count( $choices ) ) {
				return null;
			}
			$choice = array_values( $choices )[0];
			if ( ! is_array( $choice ) || ! is_string( $choice['label'] ?? null ) ) {
				return null;
			}
			$target   = 'checkbox';
			$settings = array(
				'mode' => 'single',
				'text' => $choice['label'],
			);
		} elseif ( in_array( $type, array( 'select', 'checkbox', 'radio' ), true ) ) {
			// Dynamic choices need plugin queries and are deliberately not approximated.
			if ( ! empty( $source['dynamic_choices'] ) || ! is_array( $source['choices'] ?? null ) || array() === $source['choices'] ) {
				return null;
			}
			$options = array();
			foreach ( $source['choices'] as $choice ) {
				if ( ! is_array( $choice ) || ! is_string( $choice['label'] ?? null ) ) {
					return null;
				}
				$value     = ! empty( $source['show_values'] ) && is_string( $choice['value'] ?? null ) ? $choice['value'] : $choice['label'];
				$options[] = array(
					'label' => $choice['label'],
					'value' => $value,
				);
			}
			$settings['options'] = $options;
			if ( 'select' === $type ) {
				$settings['multiple'] = ! empty( $source['multiple'] );
			}
		}
		$field                = Source::field( $index, 'field_' . $index, $target, $label, ! empty( $source['required'] ), $settings );
		$field['description'] = is_string( $source['description'] ?? null ) ? $source['description'] : '';
		if ( 'name' === $type ) {
			if ( 'simple' === ( $source['format'] ?? 'first-last' ) ) {
				$field['settings']['default']     = $this->literal( $source['simple_default'] ?? '' );
				$field['settings']['placeholder'] = $this->literal( $source['simple_placeholder'] ?? '' );
			} else {
				$field['type']     = 'group';
				$field['settings'] = array();
				$field['children'] = array();
				$parts             = array(
					'first' => __( 'First name', 'sw-forms' ),
					'last'  => __( 'Last name', 'sw-forms' ),
				);
				if ( 'first-middle-last' === ( $source['format'] ?? '' ) ) {
					$parts = array(
						'first'  => __( 'First name', 'sw-forms' ),
						'middle' => __( 'Middle name', 'sw-forms' ),
						'last'   => __( 'Last name', 'sw-forms' ),
					);
				}
				foreach ( $parts as $part => $part_label ) {
					$field['children'][] = array(
						'id'       => 'import_' . $index . '_' . $part,
						'key'      => $part,
						'type'     => 'input',
						'label'    => $part_label,
						'required' => ! empty( $source['required'] ) && 'middle' !== $part,
						'settings' => array(
							'placeholder' => $this->literal( $source[ $part . '_placeholder' ] ?? '' ),
							'default'     => $this->literal( $source[ $part . '_default' ] ?? '' ),
						),
					);
				}
			}
		}
		return $field;
	}

	private function literal( mixed $value ): string {
		return is_string( $value ) && ! str_contains( $value, '{' ) ? $value : '';
	}
}
