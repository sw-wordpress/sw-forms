<?php
/**
 * Validates collected values. Hidden elements (and everything inside them) are skipped.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Submission;

use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Visibility;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\GroupField;
use SWForms\WordPress\Support\Messages;

final class SubmissionValidator {

	public function __construct(
		private readonly Tree $tree,
		private readonly FieldRegistry $registry,
	) {}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, string> Error message per path (`id` or `rep[0].id`).
	 */
	public function validate( array $values, Visibility $visibility ): array {
		$errors = array();
		$this->walk( $this->tree->rootIds(), $values, $visibility, '', $errors );
		return $errors;
	}

	/**
	 * @param list<string>          $ids
	 * @param array<string, mixed>  $values
	 * @param array<string, string> $errors
	 */
	private function walk( array $ids, array $values, Visibility $visibility, string $prefix, array &$errors ): void {
		foreach ( $ids as $id ) {
			if ( ! $visibility->isVisible( $id, $prefix ) ) {
				continue;
			}
			$element = $this->tree->node( $id );
			if ( $this->tree->isRepeater( $id ) ) {
				$rows        = is_array( $values[ $id ] ?? null ) ? $values[ $id ] : array();
				list( $min ) = GroupField::row_limits( $element );
				if ( count( $rows ) < $min ) {
					$errors[ $prefix . $id ] = Messages::validation( 'min_rows', array( 'min' => $min ) );
				}
				foreach ( array_values( $rows ) as $index => $row ) {
					$this->walk( $this->tree->childIds( $id ), is_array( $row ) ? $row : array(), $visibility, $prefix . $id . '[' . $index . '].', $errors );
				}
				continue;
			}
			if ( ! $this->tree->isLeafData( $id ) ) {
				$this->walk( $this->tree->childIds( $id ), $values, $visibility, $prefix, $errors );
				continue;
			}
			$type = $this->registry->get( $this->tree->type( $id ) );
			if ( null === $type ) {
				continue;
			}
			$value = $values[ $id ] ?? null;
			if ( $type->is_empty( $value, $element ) ) {
				if ( ! empty( $element['required'] ) ) {
					$errors[ $prefix . $id ] = Messages::validation( 'required' );
				}
				continue;
			}
			$problems = $type->validate( $value, $element );
			if ( array() !== $problems ) {
				$errors[ $prefix . $id ] = Messages::validation( $problems[0]['code'], $problems[0]['params'] ?? array() );
			}
		}
	}
}
