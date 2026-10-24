<?php
/**
 * Reads values keyed by element id from request data (or produces initial defaults).
 *
 * Request layout: `swf[<id>]`, lists as `swf[<id>][]`, repeater rows as `swf[<repeater>][<n>][<id>]`.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Submission;

use SWForms\Core\Form\Tree;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\GroupField;

final class ValueCollector {

	public function __construct(
		private readonly Tree $tree,
		private readonly FieldRegistry $registry,
	) {}

	/**
	 * @param array<string, mixed> $raw Unslashed `swf` request array.
	 * @return array<string, mixed>
	 */
	public function collect( array $raw ): array {
		return $this->walk( $this->tree->rootIds(), $raw, false );
	}

	/**
	 * Initial values: field defaults, repeaters with max(1, minRows) rows.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return $this->walk( $this->tree->rootIds(), array(), true );
	}

	/**
	 * Default values of one new row of a repeater.
	 *
	 * @return array<string, mixed>
	 */
	public function row_defaults( string $repeater_id ): array {
		return $this->walk( $this->tree->childIds( $repeater_id ), array(), true );
	}

	/**
	 * @param list<string>         $ids
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private function walk( array $ids, array $raw, bool $defaults ): array {
		$values = array();
		foreach ( $ids as $id ) {
			$element = $this->tree->node( $id );
			if ( $this->tree->isRepeater( $id ) ) {
				list( $min, $max ) = GroupField::row_limits( $element );
				$rows              = array();
				if ( $defaults ) {
					$initial = max( 1, $min );
					for ( $i = 0; $i < $initial; $i++ ) {
						$rows[] = $this->walk( $this->tree->childIds( $id ), array(), true );
					}
				} else {
					$raw_rows = is_array( $raw[ $id ] ?? null ) ? array_values( $raw[ $id ] ) : array();
					foreach ( array_slice( $raw_rows, 0, $max ) as $raw_row ) {
						$rows[] = $this->walk( $this->tree->childIds( $id ), is_array( $raw_row ) ? $raw_row : array(), false );
					}
				}
				$values[ $id ] = $rows;
				continue;
			}
			if ( $this->tree->isLeafData( $id ) ) {
				$type = $this->registry->get( $this->tree->type( $id ) );
				if ( null !== $type ) {
					$values[ $id ] = $defaults ? $type->default_value( $element ) : $type->collect( $raw, $element );
				}
				continue;
			}
			$values += $this->walk( $this->tree->childIds( $id ), $raw, $defaults );
		}
		return $values;
	}
}
