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
	/**
	 * Root ids split at page breaks. Without `settings.steps.enabled` the form is one step.
	 *
	 * @param array<string, mixed> $settings Normalized form settings.
	 * @return list<array{label: string, ids: list<string>}>
	 */
	public static function groups( Tree $tree, array $settings = array() ): array {
		// Stored documents from before `settings.steps` only had page breaks as the switch.
		$enabled = (bool) ( $settings['steps']['enabled'] ?? true );
		$labels  = array( (string) ( $settings['steps']['firstLabel'] ?? '' ) );
		$ids     = array( array() );
		$current = 0;
		foreach ( $tree->rootIds() as $id ) {
			if ( 'page-break' !== $tree->type( $id ) ) {
				$ids[ $current ][] = $id;
			} elseif ( $enabled ) {
				$labels[] = (string) ( $tree->node( $id )['label'] ?? '' );
				$ids[]    = array();
				++$current;
			}
		}
		$groups = array();
		foreach ( $labels as $index => $label ) {
			$groups[] = array(
				/* translators: %d: step number. */
				'label' => '' === trim( $label ) ? sprintf( __( 'Step %d', 'sw-forms' ), $index + 1 ) : $label,
				'ids'   => $ids[ $index ],
			);
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
		return array_filter( $errors, static fn ( string $path ): bool => isset( $allowed[ substr( $path, 0, strcspn( $path, '.[' ) ) ] ), ARRAY_FILTER_USE_KEY );
	}
}
