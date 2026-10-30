<?php
/**
 * Renders the elements of one form tree. Created per form render.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\Core\Form\Tree;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Submission\ValueCollector;

final class Renderer {

	private ValueCollector $collector;

	public function __construct(
		private readonly FieldRegistry $registry,
		private readonly Tree $tree,
	) {
		$this->collector = new ValueCollector( $tree, $registry );
	}

	/**
	 * @param list<string> $ids
	 */
	public function render_ids( array $ids, RenderContext $context ): string {
		$html = '';
		foreach ( $ids as $id ) {
			$element = $this->tree->node( $id );
			$type    = $this->registry->get( $this->tree->type( $id ) );
			if ( null === $type || array() === $element ) {
				continue;
			}
			$element['required'] = ! empty( $element['required'] ) || $context->required( $id );
			/**
			 * Filters the HTML of a single element.
			 *
			 * @since 0.1.0
			 *
			 * @param string               $html    Element HTML.
			 * @param array<string, mixed> $element Element definition.
			 * @param RenderContext        $context Render context.
			 */
			$markup = (string) apply_filters( 'swf_element_markup', $type->render( $element, $context ), $element, $context );
			if ( $context->disabled( $id ) ) {
				$processor = new \WP_HTML_Tag_Processor( $markup );
				while ( $processor->next_tag() ) {
					if ( in_array( $processor->get_tag(), array( 'INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'FIELDSET' ), true ) && null === $processor->get_attribute( 'disabled' ) ) {
						$processor->set_attribute( 'disabled', true );
						$processor->set_attribute( 'data-swf-logic-disabled', '1' );
					}
				}
				$markup = $processor->get_updated_html();
			}
			$html .= $markup;
		}
		return $html;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function row_defaults( string $repeater_id ): array {
		return $this->collector->row_defaults( $repeater_id );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return $this->collector->defaults();
	}

	/**
	 * Minimal element tree for the frontend script (structure, value kind and logic only;
	 * settings such as notification addresses never reach the browser).
	 *
	 * @param list<mixed> $elements
	 * @return list<array<string, mixed>>
	 */
	public function public_tree( array $elements ): array {
		$out = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$type = $this->registry->get( (string) ( $element['type'] ?? '' ) );
			if ( null === $type ) {
				continue;
			}
			$node = array(
				'id'        => (string) $element['id'],
				'type'      => $type->type(),
				'data'      => $type->is_data(),
				'container' => $type->container(),
				'kind'      => $type->value_kind( $element ),
				'logic'     => $element['logic'] ?? null,
				'required'  => ! empty( $element['required'] ),
			);
			if ( 'calculation' === $node['type'] ) {
				$node['settings'] = $element['settings'];
			}
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$node['children'] = $this->public_tree( $element['children'] );
				if ( ! empty( $element['settings']['repeatable'] ) ) {
					$node['settings'] = array( 'repeatable' => true );
				}
			}
			if ( isset( $element['columns'] ) && is_array( $element['columns'] ) ) {
				$node['columns'] = array();
				foreach ( $element['columns'] as $column ) {
					$node['columns'][] = array(
						'id'       => (string) ( $column['id'] ?? '' ),
						'logic'    => $column['logic'] ?? null,
						'children' => $this->public_tree( is_array( $column['children'] ?? null ) ? $column['children'] : array() ),
					);
				}
			}
			$out[] = $node;
		}
		return $out;
	}
}
