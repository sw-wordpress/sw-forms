<?php
/**
 * Derive calculations only from active numeric fields outside repeaters.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms;

use SWForms\Core\Calculation\Arithmetic;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Visibility;

final class Calculations {
	/** @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function apply( Tree $tree, array $values, Visibility $visibility ): array {
		foreach ( $tree->ids() as $id ) {
			if ( 'calculation' !== $tree->type( $id ) || null !== $tree->scopeOf( $id ) ) {
				continue;
			}
			$settings = $tree->node( $id )['settings'];
			$numbers  = array();
			foreach ( $settings['sources'] as $source ) {
				$field  = $source['field'];
				$raw    = $visibility->isActive( $field ) ? ( $values[ $field ] ?? '' ) : '';
				$number = is_scalar( $raw ) ? str_replace( ',', '.', trim( (string) $raw ) ) : '';
				if ( '' !== $number && ! is_numeric( $number ) ) {
					$numbers = array();
					break;
				}
				$numbers[] = (float) $number;
			}
			$values[ $id ] = Arithmetic::evaluate( $settings['operation'], $numbers, (int) $settings['decimals'] ) ?? '';
		}
		return $values;
	}
}
