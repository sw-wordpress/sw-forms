<?php
/**
 * Pages are root-level document partitions. Validation still uses the complete form tree.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms;

use SWForms\Core\Form\Tree;

final class Steps {
	/** @return list<array{label: string, ids: list<string>}> */
	public static function groups( Tree $tree ): array {
		$groups = array(
			array(
				'label' => __( 'Start', 'sw-forms' ),
				'ids'   => array(),
			),
		);
		foreach ( $tree->rootIds() as $id ) {
			if ( 'page-break' === $tree->type( $id ) ) {
				$groups[] = array(
					'label' => (string) ( $tree->node( $id )['label'] ?? '' ),
					'ids'   => array(),
				);
			} else {
				$groups[ count( $groups ) - 1 ]['ids'][] = $id;
			}
		}
		return $groups;
	}

	/** @param array<string, string> $errors
	 * @param list<string>          $ids
	 * @return array<string, string>
	 */
	public static function errors( Tree $tree, array $errors, array $ids ): array {
		$allowed = array();
		$walk    = static function ( array $nodes ) use ( &$walk, &$allowed, $tree ): void {
			foreach ( $nodes as $id ) {
				$allowed[ $id ] = true;
				$walk( $tree->childIds( $id ) );
			}
		};
		$walk( $ids );
		return array_filter( $errors, static fn ( string $path ): bool => isset( $allowed[ preg_split( '/[.\[]/', $path )[0] ] ), ARRAY_FILTER_USE_KEY );
	}
}
