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
use SWForms\WordPress\Fields\InputField;
use SWForms\WordPress\Fields\FileField;
use SWForms\WordPress\Support\Messages;

final class SubmissionValidator {

	public function __construct(
		private readonly Tree $tree,
		private readonly FieldRegistry $registry,
	) {}

	/**
	 * @param array<string, mixed> $raw Raw request values; auxiliary controls are never stored.
	 * @param array<string, mixed> $values
	 * @return array<string, string> Error message per path (`id` or `rep[0].id`).
	 */
	public function validate( array $values, Visibility $visibility, array $raw = array(), bool $store_files = true ): array {
		$errors = array();
		$this->walk( $this->tree->rootIds(), $values, $visibility, '', $errors, $raw, $store_files );
		return $errors;
	}

	/**
	 * @param list<string>          $ids
	 * @param array<string, mixed>  $values
	 * @param array<string, mixed>  $raw
	 * @param array<string, string> $errors
	 */
	private function walk( array $ids, array $values, Visibility $visibility, string $prefix, array &$errors, array $raw, bool $store_files ): void {
		foreach ( $ids as $id ) {
			if ( ! $visibility->isActive( $id, $prefix ) ) {
				continue;
			}
			$element = $this->tree->node( $id );
			$element['required'] = ! empty( $element['required'] ) || $visibility->isRequired( $id, $prefix );
			if ( $this->tree->isRepeater( $id ) ) {
				$rows        = is_array( $values[ $id ] ?? null ) ? $values[ $id ] : array();
				list( $min ) = GroupField::row_limits( $element );
				if ( count( $rows ) < $min ) {
					$errors[ $prefix . $id ] = Messages::validation( 'min_rows', array( 'min' => $min ) );
				}
				$raw_rows = is_array( $raw[ $id ] ?? null ) ? array_values( $raw[ $id ] ) : array();
				foreach ( array_values( $rows ) as $index => $row ) {
					$this->walk( $this->tree->childIds( $id ), is_array( $row ) ? $row : array(), $visibility, $prefix . $id . '[' . $index . '].', $errors, is_array( $raw_rows[ $index ] ?? null ) ? $raw_rows[ $index ] : array(), $store_files );
				}
				continue;
			}
			if ( ! $this->tree->isLeafData( $id ) ) {
				$this->walk( $this->tree->childIds( $id ), $values, $visibility, $prefix, $errors, $raw, $store_files );
				continue;
			}
			$type = $this->registry->get( $this->tree->type( $id ) );
			if ( null === $type ) {
				continue;
			}
			$value = $values[ $id ] ?? null;
			if ( $type->is_empty( $value, $element ) ) {
				if ( ( ! empty( $element['required'] ) || $visibility->isRequired( $id, $prefix ) ) ) {
					$errors[ $prefix . $id ] = Messages::validation( 'required' );
				}
				continue;
			}
			$problems = $type->validate( $value, $element );
			if ( array() === $problems && $type instanceof InputField ) {
				$problems = $type->validate_auxiliary( $value, $element, $raw );
			}
			if ( array() === $problems && $type instanceof FileField ) {
				$problems = $store_files ? $type->validate_uploads( $raw, $element ) : array( array( 'code' => 'file_storage' ) );
			}
			if ( array() === $problems ) {
				$problems = \SWForms\WordPress\Validation\ValidatorRegistry::instance()->validate( $value, $element );
			}
			if ( array() !== $problems ) {
				$errors[ $prefix . $id ] = isset( $problems[0]['message'] ) ? (string) $problems[0]['message'] : Messages::validation( $problems[0]['code'], $problems[0]['params'] ?? array() );
			}
		}
	}
}
