<?php
/**
 * Cleans a form document coming from the builder (docs/schema.md) before validation and storage.
 *
 * Unknown properties are dropped, texts are sanitized, element settings go through their type.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Forms;

use SWForms\Core\Form\TypeCatalog;
use SWForms\WordPress\Fields\AbstractFieldType;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\PresetGroupField;

final class DocumentNormalizer {

	public const SCHEMA_VERSION = 1;

	public function __construct( private readonly FieldRegistry $registry ) {}

	/**
	 * @param array<string, mixed> $document
	 * @return array{schemaVersion: int, fields: list<array<string, mixed>>, settings: array<string, mixed>}
	 */
	public function normalize( array $document ): array {
		return array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'fields'        => $this->elements( $document['fields'] ?? array() ),
			'settings'      => FormSettings::normalize( is_array( $document['settings'] ?? null ) ? $document['settings'] : array() ),
		);
	}

	/**
	 * Empty document of a new form.
	 *
	 * @return array{schemaVersion: int, fields: list<array<string, mixed>>, settings: array<string, mixed>}
	 */
	public static function blank(): array {
		return array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'fields'        => array(),
			'settings'      => FormSettings::normalize( array() ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function elements( mixed $elements ): array {
		$out = array();
		foreach ( is_array( $elements ) ? $elements : array() as $element ) {
			if ( is_array( $element ) ) {
				$out[] = $this->element( $element );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array<string, mixed>
	 */
	private function element( array $element ): array {
		$type_slug = is_string( $element['type'] ?? null ) ? $element['type'] : '';
		$type      = $this->registry->get( $type_slug );
		$out       = array(
			'id'   => is_string( $element['id'] ?? null ) ? $element['id'] : '',
			'type' => $type_slug,
		);
		if ( null === $type ) {
			// Kept as is so that validation can report the unknown type.
			return $out;
		}
		$supports = $type->supports();
		if ( in_array( 'key', $supports, true ) ) {
			$out['key'] = is_string( $element['key'] ?? null ) ? trim( $element['key'] ) : '';
		}
		if ( in_array( 'label', $supports, true ) ) {
			$out['label'] = sanitize_text_field( (string) ( is_scalar( $element['label'] ?? null ) ? $element['label'] : '' ) );
		}
		if ( in_array( 'description', $supports, true ) ) {
			$out['description'] = wp_kses( (string) ( is_scalar( $element['description'] ?? null ) ? $element['description'] : '' ), AbstractFieldType::inline_html() );
		}
		if ( in_array( 'required', $supports, true ) ) {
			$out['required'] = (bool) ( $element['required'] ?? false );
		}
		if ( in_array( 'adminLabel', $supports, true ) ) {
			$out['adminLabel'] = sanitize_text_field( (string) ( is_scalar( $element['adminLabel'] ?? null ) ? $element['adminLabel'] : '' ) );
		}
		if ( in_array( 'cssClass', $supports, true ) ) {
			$out['cssClass'] = self::css_classes( $element['cssClass'] ?? '' );
		}
		if ( in_array( 'logic', $supports, true ) ) {
			$out['logic'] = self::logic( $element['logic'] ?? null );
		}
		$out['settings'] = $type->normalize_settings( is_array( $element['settings'] ?? null ) ? $element['settings'] : array() );

		if ( TypeCatalog::CONTAINER_CHILDREN === $type->container() ) {
			$children = $element['children'] ?? array();
			if ( $type instanceof PresetGroupField && ! array_key_exists( 'children', $element ) ) {
				$children = $type->initial_children();
				foreach ( $children as &$child ) {
					// Deterministic ids make programmatic shorthand normalization repeatable.
					$child['id'] = 'part_' . substr( hash( 'sha256', $out['id'] . ':' . $child['key'] ), 0, 24 );
				}
				unset( $child );
			}
			$out['children'] = $this->elements( $children );
		} elseif ( TypeCatalog::CONTAINER_COLUMNS === $type->container() ) {
			$out['columns'] = array();
			foreach ( is_array( $element['columns'] ?? null ) ? $element['columns'] : array() as $column ) {
				if ( ! is_array( $column ) ) {
					continue;
				}
				$out['columns'][] = array(
					'id'       => is_string( $column['id'] ?? null ) ? $column['id'] : '',
					'width'    => is_numeric( $column['width'] ?? null ) ? (int) $column['width'] : 0,
					'logic'    => self::logic( $column['logic'] ?? null ),
					'children' => $this->elements( $column['children'] ?? array() ),
				);
			}
		}
		return $out;
	}

	public static function css_classes( mixed $value ): string {
		$classes = preg_split( '/\s+/', is_scalar( $value ) ? (string) $value : '' );
		$classes = array_filter( array_map( 'sanitize_html_class', is_array( $classes ) ? $classes : array() ) );
		return implode( ' ', array_slice( $classes, 0, 10 ) );
	}

	/**
	 * Logic block or null. Structure is checked by the core schema validator.
	 *
	 * @return array{action: string, match: string, rules: list<array{field: string, op: string, value: string}>}|null
	 */
	public static function logic( mixed $logic ): ?array {
		if ( ! is_array( $logic ) || ! is_array( $logic['rules'] ?? null ) || array() === $logic['rules'] ) {
			return null;
		}
		$rules = array();
		foreach ( $logic['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rules[] = array(
				'field' => is_string( $rule['field'] ?? null ) ? $rule['field'] : '',
				'op'    => is_string( $rule['op'] ?? null ) ? $rule['op'] : '',
				'value' => sanitize_text_field( (string) ( is_scalar( $rule['value'] ?? null ) ? $rule['value'] : '' ) ),
			);
		}
		return array(
			'action' => 'hide' === ( $logic['action'] ?? '' ) ? 'hide' : 'show',
			'match'  => 'any' === ( $logic['match'] ?? '' ) ? 'any' : 'all',
			'rules'  => $rules,
		);
	}
}
