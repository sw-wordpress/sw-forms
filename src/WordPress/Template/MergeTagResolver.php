<?php
/**
 * Resolves merge tags for a submission.
 *
 * Tags: {field:key} (nested: {field:group.key}), {all_fields}, {entry:id}, {entry:date}, {entry:url},
 * {form:id}, {form:title}, {site:name}, {site:url}, {admin_email}, {date}, {user:*}.
 * Add-ons can add tags with the `swf_merge_tag` filter.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Template;

use SWForms\Core\Template\MergeTags;
use SWForms\WordPress\Entries\EntryPresenter;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Submission\Submission;

final class MergeTagResolver {

	public const CONTEXT_HTML = 'html';
	public const CONTEXT_TEXT = 'text';
	public const CONTEXT_URL  = 'url';

	/** @var array<string, array<string, mixed>>|null Element per key path. */
	private ?array $elements = null;

	public function __construct(
		private readonly Submission $submission,
		private readonly FieldRegistry $registry,
	) {}

	public function replace( string $template, string $context ): string {
		return MergeTags::replace(
			$template,
			function ( string $tag, string $arg ) use ( $context ): ?string {
				if ( 'all_fields' === $tag ) {
					$nodes = ( new EntryPresenter( $this->registry ) )->present( $this->submission->document['fields'], $this->submission->data );
					return self::CONTEXT_HTML === $context ? EntryPresenter::to_html( $nodes ) : EntryPresenter::to_text( $nodes );
				}
				$value = $this->value( $tag, $arg );
				if ( null === $value ) {
					return null;
				}
				return match ( $context ) {
					self::CONTEXT_HTML => esc_html( $value ),
					self::CONTEXT_URL  => rawurlencode( $value ),
					default            => $value,
				};
			}
		);
	}

	private function value( string $tag, string $arg ): ?string {
		$form  = $this->submission->form;
		$value = match ( $tag ) {
			'field'       => $this->field( $arg ),
			'entry'       => match ( $arg ) {
				'id'    => (string) $this->submission->entry_id,
				'date'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				'url'   => $this->submission->entry_id > 0 ? admin_url( 'admin.php?page=sw-forms#/forms/' . $this->submission->form_id() . '/entries/' . $this->submission->entry_id ) : '',
				default => null,
			},
			'form'        => match ( $arg ) {
				'id'    => (string) $form['id'],
				'title' => (string) $form['title'],
				default => null,
			},
			'site'        => match ( $arg ) {
				'name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'   => home_url( '/' ),
				default => null,
			},
			'admin_email' => (string) get_option( 'admin_email' ),
			'date'        => wp_date( '' === $arg ? (string) get_option( 'date_format' ) : $arg ),
			'user'        => \SWForms\WordPress\Support\DynamicDefaults::resolve( '{user:' . $arg . '}' ),
			default       => null,
		};
		/**
		 * Resolves custom merge tags (or overrides built-in ones).
		 *
		 * @since 0.1.0
		 *
		 * @param string|null $value      Resolved value, null if unknown (tag stays as is).
		 * @param string      $tag        Tag name, e.g. `field`.
		 * @param string      $argument   Part after the colon.
		 * @param Submission  $submission Current submission.
		 */
		$value = apply_filters( 'swf_merge_tag', $value, $tag, $arg, $this->submission );
		return is_scalar( $value ) ? (string) $value : null;
	}

	/** Formatted value of `key` or `group.key`; empty when hidden or unknown. */
	private function field( string $path ): string {
		$element = $this->elements()[ $path ] ?? null;
		$value   = $this->submission->data;
		foreach ( explode( '.', $path ) as $segment ) {
			$value = is_array( $value ) && array_key_exists( $segment, $value ) ? $value[ $segment ] : null;
		}
		if ( null === $value || null === $element ) {
			return '';
		}
		$type = $this->registry->get( (string) $element['type'] );
		if ( null === $type || ( is_array( $value ) && ! array_is_list( $value ) ) ) {
			return '';
		}
		return $type->format_text( $value, $element );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function elements(): array {
		if ( null === $this->elements ) {
			$this->elements = array();
			$this->index( $this->submission->document['fields'], '' );
		}
		return $this->elements;
	}

	/**
	 * @param array<mixed> $elements
	 */
	private function index( array $elements, string $prefix ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			foreach ( is_array( $element['columns'] ?? null ) ? $element['columns'] : array() as $column ) {
				$this->index( is_array( $column['children'] ?? null ) ? $column['children'] : array(), $prefix );
			}
			$key = (string) ( $element['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$this->elements[ $prefix . $key ] = $element;
			// Repeater rows have no single value; only plain groups are addressable with dots.
			if ( is_array( $element['children'] ?? null ) && empty( $element['settings']['repeatable'] ) ) {
				$this->index( $element['children'], $prefix . $key . '.' );
			}
		}
	}
}
