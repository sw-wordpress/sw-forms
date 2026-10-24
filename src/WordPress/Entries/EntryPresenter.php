<?php
/**
 * Labelled view of entry data, built from the form version the entry was submitted against.
 *
 * Used by the entry detail (REST) and by the `{all_fields}` merge tag.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Entries;

use SWForms\Core\Form\Tree;
use SWForms\WordPress\Fields\FieldRegistry;

final class EntryPresenter {

	public function __construct( private readonly FieldRegistry $registry ) {}

	/**
	 * @param list<array<string, mixed>> $fields Elements of the form version.
	 * @param array<string, mixed>       $data   Entry data keyed by field key.
	 * @return list<array<string, mixed>> Nodes: {id, type, label, value?, children?, rows?}
	 */
	public function present( array $fields, array $data ): array {
		$out = array();
		foreach ( $fields as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = $this->registry->get( (string) ( $element['type'] ?? '' ) );
			if ( null === $type ) {
				continue;
			}
			if ( ! $type->is_data() ) {
				foreach ( is_array( $element['columns'] ?? null ) ? $element['columns'] : array() as $column ) {
					$out = array_merge( $out, $this->present( is_array( $column['children'] ?? null ) ? $column['children'] : array(), $data ) );
				}
				$out = array_merge( $out, $this->present( is_array( $element['children'] ?? null ) ? $element['children'] : array(), $data ) );
				continue;
			}
			$key = (string) ( $element['key'] ?? '' );
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$node     = array(
				'id'    => (string) $element['id'],
				'type'  => $type->type(),
				'label' => self::label( $element ),
			);
			$children = is_array( $element['children'] ?? null ) ? $element['children'] : null;
			if ( null !== $children && Tree::repeatable( $element ) ) {
				$node['rows'] = array();
				foreach ( is_array( $data[ $key ] ) ? $data[ $key ] : array() as $row ) {
					$node['rows'][] = $this->present( $children, is_array( $row ) ? $row : array() );
				}
			} elseif ( null !== $children ) {
				$node['children'] = $this->present( $children, is_array( $data[ $key ] ) ? $data[ $key ] : array() );
			} else {
				$node['value'] = $type->format_text( $data[ $key ], $element );
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $element
	 */
	public static function label( array $element ): string {
		foreach ( array( 'adminLabel', 'label', 'key' ) as $property ) {
			$value = (string) ( $element[ $property ] ?? '' );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return (string) ( $element['id'] ?? '' );
	}

	/**
	 * HTML table for notifications. All values are escaped.
	 *
	 * @param list<array<string, mixed>> $nodes
	 */
	public static function to_html( array $nodes ): string {
		$html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px">';
		foreach ( $nodes as $node ) {
			$html .= '<tr><th align="left" valign="top" style="border-bottom:1px solid #e5e7eb;width:35%">' . esc_html( (string) $node['label'] ) . '</th><td style="border-bottom:1px solid #e5e7eb">';
			if ( isset( $node['rows'] ) ) {
				foreach ( $node['rows'] as $index => $row ) {
					$html .= '<div style="margin-bottom:8px"><em>' . esc_html( '#' . ( $index + 1 ) ) . '</em>' . self::to_html( $row ) . '</div>';
				}
			} elseif ( isset( $node['children'] ) ) {
				$html .= self::to_html( $node['children'] );
			} else {
				$html .= nl2br( esc_html( (string) $node['value'] ) );
			}
			$html .= '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Plain text variant.
	 *
	 * @param list<array<string, mixed>> $nodes
	 */
	public static function to_text( array $nodes, string $indent = '' ): string {
		$text = '';
		foreach ( $nodes as $node ) {
			if ( isset( $node['rows'] ) ) {
				$text .= $indent . $node['label'] . ":\n";
				foreach ( $node['rows'] as $index => $row ) {
					$text .= $indent . '  #' . ( $index + 1 ) . "\n" . self::to_text( $row, $indent . '    ' );
				}
			} elseif ( isset( $node['children'] ) ) {
				$text .= $indent . $node['label'] . ":\n" . self::to_text( $node['children'], $indent . '  ' );
			} else {
				$text .= $indent . $node['label'] . ': ' . $node['value'] . "\n";
			}
		}
		return $text;
	}
}
