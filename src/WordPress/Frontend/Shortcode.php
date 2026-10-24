<?php
/**
 * `[sw_form id="3" title="yes"]`
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

final class Shortcode {

	public const TAG = 'sw_form';

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => '0',
				'title' => 'no',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$id   = absint( $atts['id'] );
		if ( 0 === $id ) {
			return '';
		}
		return FormView::render( $id, array( 'title' => in_array( strtolower( (string) $atts['title'] ), array( 'yes', 'true', '1' ), true ) ) );
	}
}
