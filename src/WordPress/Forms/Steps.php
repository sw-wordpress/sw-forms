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
		$groups  = array(
			array(
				'label' => (string) ( $settings['steps']['firstLabel'] ?? '' ),
				'ids'   => array(),
			),
		);
		foreach ( $tree->rootIds() as $id ) {
			if ( 'page-break' !== $tree->type( $id ) ) {
				$groups[ count( $groups ) - 1 ]['ids'][] = $id;
			} elseif ( $enabled ) {
				$groups[] = array(
					'label' => (string) ( $tree->node( $id )['label'] ?? '' ),
					'ids'   => array(),
				);
			}
		}
		foreach ( $groups as $index => $group ) {
			if ( '' === trim( $group['label'] ) ) {
				/* translators: %d: step number. */
				$groups[ $index ]['label'] = sprintf( __( 'Step %d', 'sw-forms' ), $index + 1 );
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
