<?php
/**
 * Contact Form 7 reader using its native tag scanner; never renders source markup.
 *
 * @package SWForms
 * @since 0.8.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Import;

final class ContactForm7 implements AdapterInterface {
	public function forms( int $page ): array {
		return Source::forms( 'wpcf7_contact_form', $page );
	}

	public function read( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wpcf7_contact_form' !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$form = \WPCF7_ContactForm::get_instance( $id );
		if ( null === $form ) {
			return null;
		}
		$template = $form->prop( 'form' );
		if ( ! is_string( $template ) || strlen( $template ) > 1048576 ) {
			return null;
		}
		$tags = $form->scan_form_tags();
		if ( count( $tags ) > 500 ) {
			return null;
		}
		$result = Source::result( $post->post_title );
		$fields = array();
		$known  = array();
		foreach ( $tags as $index => $tag ) {
			$known[] = $tag->type;
			if ( 'submit' === $tag->basetype ) {
				if ( isset( $tag->values[0] ) ) {
					$result['document']['settings']['submitLabel'] = $tag->values[0];
				}
				$result['imported'][] = 'submit';
				continue;
			}
			$field = $this->convert( $tag, $index, $template );
			if ( null === $field ) {
				$result['skipped'][] = $tag->name . ' (' . $tag->type . ')';
				continue;
			}
			$fields[]             = $field;
			$result['imported'][] = $tag->name . ' (' . $tag->type . ') → ' . $field['key'];
		}
		// The native scanner ignores tags from inactive add-ons. Report these as well.
		preg_match_all( '/(?<!\[)\[([a-zA-Z][a-zA-Z0-9_-]*\*?)(?=[\s\]])/', $template, $matches );
		foreach ( array_unique( $matches[1] ) as $type ) {
			if ( ! in_array( $type, $known, true ) ) {
				$result['skipped'][] = '[' . $type . ']';
			}
		}
		$result['document']['fields'] = $fields;
		return $result;
	}

	/** @return array<string, mixed>|null */
	private function convert( \WPCF7_FormTag $tag, int $index, string $template ): ?array {
		$type = $tag->basetype;
		if ( '' === $tag->name || ! in_array( $type, array( 'text', 'email', 'url', 'tel', 'number', 'date', 'hidden', 'textarea', 'select', 'checkbox', 'radio', 'acceptance' ), true ) ) {
			return null;
		}
		$target   = in_array( $type, array( 'textarea', 'select', 'checkbox', 'radio' ), true ) ? $type : 'input';
		$settings = array();
		$label    = $tag->name;
		if ( preg_match( '/<label\b[^>]*>((?:(?!<\/label>).)*\[' . preg_quote( $tag->type, '/' ) . '\s+' . preg_quote( $tag->raw_name, '/' ) . '(?=[\s\]])(?:(?!<\/label>).)*)<\/label>/si', $template, $match ) ) {
			$text  = trim( wp_strip_all_tags( (string) preg_replace( '/\[[^\]]*\]/', '', $match[1] ) ) );
			$label = '' === $text ? $label : html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		}
		if ( 'input' === $target ) {
			$settings['inputType'] = $type;
		}
		if ( in_array( $type, array( 'select', 'checkbox', 'radio' ), true ) ) {
			if ( array() === $tag->values || $tag->has_option( 'data' ) || $tag->has_option( 'exclusive' ) || $tag->has_option( 'free_text' ) ) {
				return null;
			}
			$settings['options'] = array();
			foreach ( $tag->raw_values as $value ) {
				// CF7 pipes substitute mail values only; submitted values are the left side.
				$value                 = explode( '|', $value, 2 )[0];
				$settings['options'][] = array(
					'label' => $value,
					'value' => $value,
				);
			}
			if ( 'select' === $type ) {
				$settings['multiple'] = $tag->has_option( 'multiple' );
				if ( $tag->has_option( 'first_as_label' ) ) {
					$first                   = array_shift( $settings['options'] );
					$settings['placeholder'] = $first['label'] ?? '';
					if ( array() === $settings['options'] ) {
						return null;
					}
				}
			}
		} elseif ( 'acceptance' === $type ) {
			if ( $tag->has_option( 'invert' ) ) {
				return null;
			}
			$target   = 'checkbox';
			$settings = array(
				'mode' => 'single',
				'text' => $tag->content,
			);
		} elseif ( isset( $tag->values[0] ) ) {
			$settings[ $tag->has_option( 'placeholder' ) || $tag->has_option( 'watermark' ) ? 'placeholder' : 'default' ] = $tag->values[0];
		}
		$required = $tag->is_required() || 'radio' === $type || ( 'acceptance' === $type && ! $tag->has_option( 'optional' ) );
		// Index suffix prevents collisions after replacing CF7 hyphens with underscores.
		return Source::field( $index, substr( $tag->name, 0, 45 ) . '_' . $index, $target, $label, $required, $settings );
	}
}
