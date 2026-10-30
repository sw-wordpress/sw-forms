<?php
/**
 * Synthetic test data: never reuse a real visitor's entry for the builder test button.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Webhooks;

use SWForms\Core\Form\DataBuilder;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Submission\Submission;

final class SampleSubmission {

	/** @param array<string, mixed> $form Form row.
	 * @param array<string, mixed> $document Editable document.
	 */
	public static function create( array $form, array $document ): Submission {
		$tree       = Tree::build( $document['fields'], FieldRegistry::instance() );
		$values     = self::values( $tree, $tree->rootIds() );
		$visibility = ( new Evaluator( $tree ) )->evaluate( $values );
		return new Submission( $form, $form['current_version'], $document, $tree, $values, $visibility, ( new DataBuilder( $tree ) )->build( $values, $visibility ), 0, new EntryRepository() );
	}

	/** @param list<string> $ids Nodes.
	 * @return array<string, mixed>
	 */
	private static function values( Tree $tree, array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$node = $tree->node( $id );
			if ( $tree->isRepeater( $id ) ) {
				$out[ $id ] = array( self::values( $tree, $tree->childIds( $id ) ) );
			} elseif ( ! $tree->isLeafData( $id ) ) {
				$out += self::values( $tree, $tree->childIds( $id ) );
			} else {
				$type     = $node['type'];
				$settings = $node['settings'] ?? array();
				$option   = $settings['options'][0]['value'] ?? 'example';
				$example  = match ( $settings['inputType'] ?? '' ) {
					'email' => 'example@example.org',
					'url' => 'https://example.org',
					'number' => '42',
					'date' => '2026-01-01',
					'time' => '12:00',
					'datetime-local' => '2026-01-01T12:00',
					default => 'Example',
				};
				$out[ $id ] = match ( $type ) {
					'file', 'image', 'signature', 'audio', 'video' => array(),
					'checkbox' => 'single' === ( $settings['mode'] ?? 'single' ) ? true : array( $option ),
					'radio' => $option,
					'select' => ! empty( $settings['multiple'] ) ? array( $option ) : $option,
					'matrix' => array_fill_keys( array_column( $settings['rows'] ?? array(), 'value' ), $option ),
					'tags' => array( 'example' ),
					'range', 'rating', 'scale' => '3',
					'color' => '#336699',
					'birthdate' => '2000-01-01',
					'date-interval' => array(
						'from' => '2026-01-01',
						'to'   => '2026-01-02',
					),
					'time-interval' => array(
						'from' => '12:00',
						'to'   => '13:00',
					),
					default => $example,
				};
			}
		}
		return $out;
	}
}
