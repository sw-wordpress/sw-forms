<?php
/**
 * Normalises element settings against a settings schema (docs/extending.md "Settings schema").
 *
 * Supported setting types: text, textarea, html, code, number, toggle, select, options, list.
 * Unknown settings are dropped.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

final class SettingsNormalizer {

	public const TYPES        = array( 'text', 'textarea', 'html', 'code', 'number', 'toggle', 'select', 'options', 'list' );
	private const MAX_OPTIONS = 500;

	/**
	 * @param list<array<string, mixed>> $schema
	 * @param array<string, mixed>       $settings
	 * @return array<string, mixed>
	 */
	public static function normalize( array $schema, array $settings ): array {
		$out = array();
		foreach ( $schema as $definition ) {
			$name         = (string) $definition['name'];
			$default      = $definition['default'] ?? null;
			$out[ $name ] = array_key_exists( $name, $settings )
				? self::value( $definition, $settings[ $name ], $default )
				: $default;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private static function value( array $definition, mixed $value, mixed $fallback ): mixed {
		switch ( $definition['type'] ?? 'text' ) {
			case 'toggle':
				return (bool) $value;
			case 'number':
				return self::number( $definition, $value, $fallback );
			case 'select':
				$allowed = array_map( static fn ( array $choice ): string => (string) $choice['value'], (array) ( $definition['choices'] ?? array() ) );
				return is_scalar( $value ) && in_array( (string) $value, $allowed, true ) ? (string) $value : $fallback;
			case 'textarea':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : $fallback;
			case 'html':
				return is_scalar( $value ) ? wp_kses( (string) $value, AbstractFieldType::inline_html() ) : $fallback;
			case 'code':
				// Patterns and similar: keep characters such as `<` that sanitize_text_field() would strip.
				return is_scalar( $value ) ? mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', wp_check_invalid_utf8( (string) $value ) ), 0, 500 ) : $fallback;
			case 'options':
				return self::options( $value );
			case 'list':
				$list = array();
				foreach ( is_array( $value ) ? $value : array() as $item ) {
					if ( is_scalar( $item ) ) {
						$list[] = sanitize_text_field( (string) $item );
					}
				}
				return array_values( array_unique( $list ) );
			default:
				return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 1000 ) : $fallback;
		}
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private static function number( array $definition, mixed $value, mixed $fallback ): mixed {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return $fallback;
		}
		$number = ! empty( $definition['integer'] ) ? (int) $value : (float) $value;
		if ( isset( $definition['min'] ) && $number < $definition['min'] ) {
			$number = $definition['min'];
		}
		if ( isset( $definition['max'] ) && $number > $definition['max'] ) {
			$number = $definition['max'];
		}
		return $number;
	}

	/**
	 * @return list<array{label: string, value: string}>
	 */
	public static function options( mixed $value ): array {
		$out  = array();
		$seen = array();
		foreach ( is_array( $value ) ? $value : array() as $option ) {
			if ( ! is_array( $option ) || count( $out ) >= self::MAX_OPTIONS ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( is_scalar( $option['label'] ?? null ) ? $option['label'] : '' ) );
			$val   = sanitize_text_field( (string) ( is_scalar( $option['value'] ?? null ) ? $option['value'] : '' ) );
			if ( '' === $val ) {
				$val = $label;
			}
			if ( '' === $val || isset( $seen[ $val ] ) ) {
				continue;
			}
			$seen[ $val ] = true;
			$out[]        = array(
				'label' => '' === $label ? $val : $label,
				'value' => $val,
			);
		}
		return $out;
	}
}
