<?php
/**
 * Base class with schema driven settings normalisation and accessible wrapper markup.
 *
 * Public extension API; stable from 0.4.0.
 *
 * @since 0.1.0
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Form\DataBuilder;
use SWForms\Core\Form\TypeCatalog;
use SWForms\WordPress\Frontend\RenderContext;

abstract class AbstractFieldType implements FieldTypeInterface {

	/** Hard cap for any single text value. */
	protected const MAX_TEXT = 10000;

	/** Input types whose label can float inside the control. */
	private const FLOATING_INPUT_TYPES = array( 'text', 'email', 'tel', 'url', 'number', 'password', 'search', 'date', 'time', 'datetime-local' );

	public function is_data(): bool {
		return true;
	}

	public function container(): string {
		return TypeCatalog::CONTAINER_NONE;
	}

	public function supports(): array {
		return array( 'key', 'label', 'description', 'required', 'cssClass', 'adminLabel', 'logic' );
	}

	public function default_settings(): array {
		$defaults = array();
		foreach ( $this->settings_schema() as $setting ) {
			$defaults[ (string) $setting['name'] ] = $setting['default'] ?? null;
		}
		return $defaults;
	}

	public function normalize_settings( array $settings ): array {
		return SettingsNormalizer::normalize( $this->settings_schema(), $settings );
	}

	public function default_value( array $element ): mixed {
		$default = $this->setting( $element, 'default' );
		return is_scalar( $default ) ? (string) $default : '';
	}

	public function collect( array $raw, array $element ): mixed {
		$value = $raw[ $element['id'] ] ?? '';
		return is_scalar( $value ) ? $this->limit( sanitize_text_field( (string) $value ) ) : '';
	}

	public function is_empty( mixed $value, array $element ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		if ( is_bool( $value ) ) {
			return ! $value;
		}
		return '' === trim( is_scalar( $value ) ? (string) $value : '' );
	}

	public function validate( mixed $value, array $element ): array {
		return array();
	}

	public function format_text( mixed $value, array $element ): string {
		return DataBuilder::stringify( $value );
	}

	public function value_kind( array $element ): string {
		return 'text';
	}

	/**
	 * @param array<string, mixed> $element
	 */
	protected function setting( array $element, string $name, mixed $fallback = null ): mixed {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
		return $settings[ $name ] ?? $fallback;
	}

	protected function limit( string $value, int $max = self::MAX_TEXT ): string {
		return mb_substr( $value, 0, $max, 'UTF-8' );
	}

	/**
	 * Options of a choice element as value => label.
	 *
	 * @param array<string, mixed> $element
	 * @return array<string, string>
	 */
	protected function options( array $element ): array {
		$out     = array();
		$options = $this->setting( $element, 'options', array() );
		foreach ( is_array( $options ) ? $options : array() as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] ) ) {
				$out[ (string) $option['value'] ] = is_scalar( $option['label'] ?? null ) ? (string) $option['label'] : (string) $option['value'];
			}
		}
		return $out;
	}

	/**
	 * @param array<string, string|int|bool|null> $attributes `true` renders a boolean attribute, false/null omits it.
	 */
	protected function attrs( array $attributes ): string {
		$out = '';
		foreach ( $attributes as $name => $value ) {
			if ( null === $value || false === $value ) {
				continue;
			}
			$out .= true === $value ? ' ' . $name : ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	/**
	 * Attributes that connect a control with its description and error message.
	 *
	 * @param array<string, mixed> $element
	 * @return array<string, string|bool|null>
	 */
	protected function aria( array $element, RenderContext $context ): array {
		$id          = (string) $element['id'];
		$dom_id      = $context->dom_id( $id );
		$described   = array();
		$description = (string) ( $element['description'] ?? '' );
		if ( '' !== $description ) {
			$described[] = $dom_id . '-desc';
		}
		$described[] = $dom_id . '-err';
		$has_error   = '' !== $context->error( $id );
		return array(
			'aria-describedby' => implode( ' ', $described ),
			'aria-invalid'     => $has_error ? 'true' : null,
			'aria-required'    => ! empty( $element['required'] ) ? 'true' : null,
		);
	}

	/**
	 * Accessible wrapper: label (or fieldset/legend for groups of controls), description and error slot.
	 *
	 * @param array<string, mixed>           $element
	 * @param array<string, string|int|bool> $extra Additional attributes of the wrapper.
	 */
	protected function wrap( array $element, RenderContext $context, string $control, bool $fieldset = false, array $extra = array() ): string {
		$id       = (string) $element['id'];
		$dom_id   = $context->dom_id( $id );
		$label    = (string) ( $element['label'] ?? '' );
		$required = ! empty( $element['required'] );
		$error    = $context->error( $id );
		$classes  = array( 'swf-field', 'swf-field--' . $this->type() );
		if ( '' !== $error ) {
			$classes[] = 'swf-field--error';
		}
		$floating = ! $fieldset && '' !== $label && $context->floating_labels() ? $this->floating_control( $control ) : null;
		if ( null !== $floating ) {
			$control   = $floating;
			$classes[] = 'swf-field--floatable';
			if ( ! $this->is_empty( $context->value( $id ), $element ) ) {
				$classes[] = 'swf-field--filled';
			}
		}
		$css_class = (string) ( $element['cssClass'] ?? '' );
		if ( '' !== $css_class ) {
			$classes[] = $css_class;
		}

		$label_html = '';
		if ( '' !== $label ) {
			$marker     = $required
				? '<span class="swf-required" aria-hidden="true">*</span><span class="swf-sr">' . esc_html__( '(required)', 'sw-forms' ) . '</span>'
				: '';
			$label_html = $fieldset
				? '<legend class="swf-label">' . esc_html( $label ) . $marker . '</legend>'
				: '<label class="swf-label" for="' . esc_attr( $dom_id ) . '">' . esc_html( $label ) . $marker . '</label>';
		}

		$description = (string) ( $element['description'] ?? '' );
		$desc_html   = '' !== $description
			? '<p class="swf-description" id="' . esc_attr( $dom_id . '-desc' ) . '">' . wp_kses( $description, self::inline_html() ) . '</p>'
			: '';
		$error_html  = '<p class="swf-error-message" id="' . esc_attr( $dom_id . '-err' ) . '"' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</p>';

		$tag  = $fieldset ? 'fieldset' : 'div';
		$open = '<' . $tag . $this->attrs(
			$extra + array(
				'class'            => implode( ' ', $classes ),
				'data-swf-id'      => $id,
				'data-swf-path'    => $context->path( $id ),
				'aria-describedby' => $fieldset ? $dom_id . ( '' !== $description ? '-desc ' . $dom_id : '' ) . '-err' : null,
				'hidden'           => $context->hidden( $id ),
			)
		) . '>';

		return $open . $label_html . $control . $desc_html . $error_html . '</' . $tag . '>';
	}

	/**
	 * A control markup with exactly one text-like input, textarea or single select can show a
	 * floating label. Returns the markup with a blank placeholder (so `:placeholder-shown`
	 * reflects programmatic and restored values in CSS) or null when the label must stay above.
	 */
	private function floating_control( string $control ): ?string {
		$processor = new \WP_HTML_Tag_Processor( $control );
		$controls  = 0;
		$floatable = false;
		while ( $processor->next_tag() ) {
			$tag  = $processor->get_tag();
			$type = strtolower( (string) ( $processor->get_attribute( 'type' ) ?? 'text' ) );
			if ( ! in_array( $tag, array( 'INPUT', 'SELECT', 'TEXTAREA', 'BUTTON' ), true ) || ( 'INPUT' === $tag && 'hidden' === $type ) ) {
				continue;
			}
			++$controls;
			$floatable = match ( $tag ) {
				'INPUT'    => in_array( $type, self::FLOATING_INPUT_TYPES, true ),
				'TEXTAREA' => true,
				'SELECT'   => null === $processor->get_attribute( 'multiple' ) && null === $processor->get_attribute( 'data-swf-search' ),
				default    => false,
			};
			if ( $floatable && 'SELECT' !== $tag && null === $processor->get_attribute( 'placeholder' ) ) {
				$processor->set_attribute( 'placeholder', ' ' );
			}
		}
		return 1 === $controls && $floatable ? $processor->get_updated_html() : null;
	}

	/**
	 * Inline HTML allowed in descriptions and checkbox texts.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function inline_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}
}
