<?php
/**
 * Entry value editing against the immutable form version, without replaying actions.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Entries;

use SWForms\Core\Form\DataBuilder;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\FileField;
use SWForms\WordPress\Fields\InputField;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Submission\SubmissionValidator;

final class EntryEditor {

	/** @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public function definition( array $entry ): array {
		$document = ( new FormRepository() )->version( $entry['formId'], $entry['formVersion'] ) ?? array();
		$tree     = Tree::build( $document['fields'] ?? array(), FieldRegistry::instance() );
		return array(
			'fields'   => $document['fields'] ?? array(),
			'values'   => $this->from_data( $tree, $tree->rootIds(), $entry['data'] ),
			'revision' => self::revision( $entry['data'] ),
		);
	}

	/** @param array<string, mixed> $data */
	public static function revision( array $data ): string {
		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/** @param list<string>         $ids
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function from_data( Tree $tree, array $ids, array $data ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$node = $tree->node( $id );
			$key  = $node['key'] ?? '';
			if ( $tree->isRepeater( $id ) ) {
				$out[ $id ] = array_map( fn ( array $row ): array => $this->from_data( $tree, $tree->childIds( $id ), $row ), is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array() );
			} elseif ( $tree->isLeafData( $id ) ) {
				$value      = $data[ $key ] ?? '';
				$out[ $id ] = FieldRegistry::instance()->get( $tree->type( $id ) ) instanceof FileField ? array_column( is_array( $value ) ? $value : array(), 'name' ) : $value;
			} else {
				$out += $this->from_data( $tree, $tree->childIds( $id ), $tree->isData( $id ) ? ( $data[ $key ] ?? array() ) : $data );
			}
		}
		return $out;
	}

	/** @param array<string, mixed> $entry
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save( array $entry, array $raw, string $revision ): array|\WP_Error {
		$registry                           = FieldRegistry::instance();
		$doc                                = ( new FormRepository() )->version( $entry['formId'], $entry['formVersion'] ) ?? array();
		$tree                               = Tree::build( $doc['fields'] ?? array(), $registry );
		$shape_errors                       = array();
		list( $values, $stored, $prepared ) = $this->collect( $tree, $tree->rootIds(), $raw, $entry['data'], '', $shape_errors );
		$visibility                         = ( new Evaluator( $tree ) )->evaluate( $values );
		$calculated                         = \SWForms\WordPress\Forms\Calculations::apply( $tree, $values, $visibility );
		foreach ( $tree->ids() as $field_id ) {
			if ( 'calculation' === $tree->type( $field_id ) ) {
				$stored[ $field_id ] = $calculated[ $field_id ] ?? '';
			}
		}
		$values = $calculated;
		$errors = $shape_errors + ( new SubmissionValidator( $tree, $registry ) )->validate( $values, $visibility, $prepared );
		if ( array() !== $errors ) {
			return new \WP_Error(
				'swf_invalid_entry',
				__( 'Please correct the marked fields.', 'sw-forms' ),
				array(
					'status' => 422,
					'errors' => $errors,
				)
			);
		}
		$builder = new DataBuilder( $tree );
		$data    = $builder->build( $stored, $visibility );
		$index   = $builder->flatten(
			$stored,
			$visibility,
			static function ( string $id, array $node, mixed $value ) use ( $registry ): string {
				return $registry->get( $node['type'] )?->format_text( $value, $node ) ?? '';
			}
		);
		$repo    = new EntryRepository();
		if ( ! $repo->replace_data( $entry['id'], $data, $index, $revision ) ) {
			return new \WP_Error( 'swf_entry_conflict', __( 'The entry changed or could not be saved. Reload it before editing again.', 'sw-forms' ), array( 'status' => 409 ) );
		}
		$repo->log( $entry['id'], $entry['formId'], 'edit', (string) get_current_user_id(), 'success', __( 'Entry values updated.', 'sw-forms' ) );
		return $repo->find_visible( $entry['id'] ) ?? array();
	}

	/** @param list<string>          $ids
	 * @param array<string, mixed>  $raw
	 * @param array<string, mixed>  $old
	 * @param array<string, string> $errors
	 * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>}
	 */
	private function collect( Tree $tree, array $ids, array $raw, array $old, string $prefix, array &$errors ): array {
		$values   = array();
		$stored   = array();
		$prepared = $raw;
		foreach ( $ids as $id ) {
			$node = $tree->node( $id );
			$key  = $node['key'] ?? '';
			if ( $tree->isRepeater( $id ) ) {
				$rows     = is_array( $raw[ $id ] ?? null ) ? array_values( $raw[ $id ] ) : array();
				$old_rows = is_array( $old[ $key ] ?? null ) ? $old[ $key ] : array();
				if ( count( $rows ) !== count( $old_rows ) ) {
					$errors[ $prefix . $id ] = __( 'Keep the existing repeater rows when editing an entry.', 'sw-forms' );
				}
				$values[ $id ]   = array();
				$stored[ $id ]   = array();
				$prepared[ $id ] = array();
				foreach ( $old_rows as $i => $row ) {
					list( $v, $s, $p ) = $this->collect( $tree, $tree->childIds( $id ), is_array( $rows[ $i ] ?? null ) ? $rows[ $i ] : array(), $row, $prefix . $id . '[' . $i . '].', $errors );
					$values[ $id ][]   = $v;
					$stored[ $id ][]   = $s;
					$prepared[ $id ][] = $p;
				}
			} elseif ( ! $tree->isLeafData( $id ) ) {
				list( $v, $s, $p ) = $this->collect( $tree, $tree->childIds( $id ), $prepared, $tree->isData( $id ) ? ( $old[ $key ] ?? array() ) : $old, $prefix, $errors );
				$values           += $v;
				$stored           += $s;
				$prepared          = $p;
			} else {
				$type = FieldRegistry::instance()->get( $tree->type( $id ) );
				if ( $type instanceof FileField ) {
					$files         = is_array( $old[ $key ] ?? null ) ? $old[ $key ] : array();
					$values[ $id ] = array_column( $files, 'name' );
					$stored[ $id ] = $files;
					unset( $prepared[ $id ] );
				} elseif ( null !== $type ) {
					if ( $type instanceof InputField ) {
						$prepared[ $id . ':confirm' ] = $prepared[ $id ] ?? '';
						if ( ! empty( $node['settings']['countryCode'] ) ) {
							foreach ( $node['settings']['countryCodes'] ?? array() as $country ) {
								if ( is_string( $prepared[ $id ] ?? null ) && str_starts_with( $prepared[ $id ], $country['value'] ) ) {
									$prepared[ $id . ':country' ] = $country['value'];
									$prepared[ $id ]              = ltrim( substr( $prepared[ $id ], strlen( $country['value'] ) ) );
									break;
								}
							}
						}
					}
					$values[ $id ] = $type->collect( $prepared, $node );
					$stored[ $id ] = $values[ $id ];
				}
			}
		}
		return array( $values, $stored, $prepared );
	}
}
