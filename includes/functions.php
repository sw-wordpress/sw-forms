<?php
/**
 * Public PHP API (docs/extending.md).
 *
 * @package SWForms
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'swf_render_form' ) ) {
	/**
	 * Returns the HTML of a form, e.g. `echo swf_render_form( 3 );` in a theme template.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $form_id Form id.
	 * @param bool $title   Show the form title.
	 */
	function swf_render_form( int $form_id, bool $title = false ): string {
		return \SWForms\WordPress\Frontend\FormView::render( $form_id, array( 'title' => $title ) );
	}
}

if ( ! function_exists( 'swf_get_form' ) ) {
	/**
	 * The published document of a form (docs/schema.md) or null.
	 *
	 * @since 0.1.0
	 *
	 * @param int $form_id Form id.
	 * @return array<string, mixed>|null
	 */
	function swf_get_form( int $form_id ): ?array {
		return ( new \SWForms\WordPress\Forms\FormRepository() )->published( $form_id );
	}
}

if ( ! function_exists( 'swf_submit' ) ) {
	/**
	 * Submits values programmatically through the full pipeline (validation, storage, actions)
	 * without spam checks. Values are keyed by element id as in the HTML form (`swf[...]`).
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $values  Raw values keyed by element id.
	 */
	function swf_submit( int $form_id, array $values ): \SWForms\WordPress\Submission\SubmissionResult {
		return \SWForms\WordPress\Submission\SubmissionService::create_default()->submit(
			$form_id,
			$values,
			array(
				'trusted' => true,
				'user_id' => get_current_user_id(),
			)
		);
	}
}
