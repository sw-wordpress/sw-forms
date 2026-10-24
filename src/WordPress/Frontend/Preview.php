<?php
/**
 * Preview of the draft inside the active theme: `/?swf_preview=3&_wpnonce=...` (builder iframe).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\WordPress\Support\Capabilities;

final class Preview {

	public const NONCE_ACTION = 'swf_preview';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), -1 );
	}

	public static function url( int $form_id ): string {
		return add_query_arg(
			array(
				'swf_preview' => $form_id,
				'_wpnonce'    => wp_create_nonce( self::NONCE_ACTION ),
			),
			home_url( '/' )
		);
	}

	public function handle(): void {
		if ( ! isset( $_GET['swf_preview'] ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to preview this form.', 'sw-forms' ), 403 );
		}
		$form_id = absint( $_GET['swf_preview'] );
		nocache_headers();
		show_admin_bar( false );
		Assets::enqueue();
		$form = FormView::render( $form_id, array( 'preview' => true ) );

		if ( wp_is_block_theme() ) {
			echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core function returns escaped attributes.
			wp_head();
			echo '</head><body class="swf-preview-body"><main class="swf-preview" style="box-sizing:border-box;width:100%;max-width:760px;margin:2rem auto;padding:0 1rem">' . $form . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the field types.
			wp_footer();
			echo '</body></html>';
			exit;
		}
		get_header();
		echo '<main class="swf-preview" style="box-sizing:border-box;width:100%;max-width:760px;margin:2rem auto;padding:0 1rem">' . $form . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the field types.
		get_footer();
		exit;
	}
}
