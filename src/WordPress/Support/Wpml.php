<?php
/**
 * WPML String Translation integration (active only when WPML is installed and active).
 *
 * Visitor facing texts are registered when a form is saved and translated when it is rendered.
 * There is one form per site, not one copy per language.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Support;

final class Wpml {

	public const CONTEXT = 'SW Forms';

	public function register(): void {
		add_action( 'swf_form_saved', array( $this, 'register_strings' ), 10, 3 );
	}

	public static function active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * @param array<string, mixed> $document
	 */
	public function register_strings( int $form_id, int $version, array $document ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( ! self::active() ) {
			return;
		}
		self::walk(
			$document,
			$form_id,
			static function ( string $name, string $value ): string {
				do_action( 'wpml_register_single_string', self::CONTEXT, $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
				return $value;
			}
		);
	}

	/**
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	public static function translate( array $document, int $form_id ): array {
		if ( ! self::active() ) {
			return $document;
		}
		return self::walk(
			$document,
			$form_id,
			static fn ( string $name, string $value ): string => (string) apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $name ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's hook.
		);
	}

	/**
	 * Applies $callback to every translatable text and returns the changed document.
	 *
	 * @param array<string, mixed>             $document
	 * @param callable(string, string): string $callback Receives the string name and value.
	 * @return array<string, mixed>
	 */
	private static function walk( array $document, int $form_id, callable $callback ): array {
		$prefix = 'form-' . $form_id . '-';
		foreach ( array( 'submitLabel' ) as $setting ) {
			if ( isset( $document['settings'][ $setting ] ) && '' !== $document['settings'][ $setting ] ) {
				$document['settings'][ $setting ] = $callback( $prefix . $setting, (string) $document['settings'][ $setting ] );
			}
		}
		if ( isset( $document['settings']['confirmation']['message'] ) && '' !== $document['settings']['confirmation']['message'] ) {
			$document['settings']['confirmation']['message'] = $callback( $prefix . 'confirmation', (string) $document['settings']['confirmation']['message'] );
		}
		$document['fields'] = self::elements( is_array( $document['fields'] ?? null ) ? $document['fields'] : array(), $prefix, $callback );
		return $document;
	}

	/**
	 * @param array<mixed>                     $elements
	 * @param callable(string, string): string $callback
	 * @return array<mixed>
	 */
	private static function elements( array $elements, string $prefix, callable $callback ): array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id = (string) ( $element['id'] ?? '' );
			foreach ( array( 'label', 'description' ) as $property ) {
				if ( isset( $element[ $property ] ) && '' !== $element[ $property ] ) {
					$element[ $property ] = $callback( $prefix . $id . '-' . $property, (string) $element[ $property ] );
				}
			}
			foreach ( array( 'placeholder', 'text', 'otherLabel', 'addLabel', 'patternMessage' ) as $setting ) {
				if ( isset( $element['settings'][ $setting ] ) && is_string( $element['settings'][ $setting ] ) && '' !== $element['settings'][ $setting ] ) {
					$element['settings'][ $setting ] = $callback( $prefix . $id . '-' . $setting, $element['settings'][ $setting ] );
				}
			}
			foreach ( is_array( $element['settings']['options'] ?? null ) ? $element['settings']['options'] : array() as $option_index => $option ) {
				$element['settings']['options'][ $option_index ]['label'] = $callback( $prefix . $id . '-option-' . (string) $option['value'], (string) $option['label'] );
			}
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$element['children'] = self::elements( $element['children'], $prefix, $callback );
			}
			foreach ( is_array( $element['columns'] ?? null ) ? $element['columns'] : array() as $column_index => $column ) {
				$element['columns'][ $column_index ]['children'] = self::elements( is_array( $column['children'] ?? null ) ? $column['children'] : array(), $prefix, $callback );
			}
			$elements[ $index ] = $element;
		}
		return $elements;
	}
}
