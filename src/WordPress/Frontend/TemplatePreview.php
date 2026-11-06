<?php
/**
 * Non-persistent preview of a starter template inside the active theme:
 * `/?swf_template_preview=<template-id>&_wpnonce=…`. Form managers only; the demo form has no
 * postback target, so nothing typed there is sent or stored (same contract as the design gallery).
 *
 * @package SWForms
 * @since 0.7.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Frontend;

use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\DocumentNormalizer;
use SWForms\WordPress\Forms\Templates;
use SWForms\WordPress\Support\Capabilities;

final class TemplatePreview {

	public const NONCE_ACTION = 'swf_template_preview';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), -1 );
	}

	public static function url( string $template_id ): string {
		return add_query_arg(
			array(
				'swf_template_preview' => rawurlencode( $template_id ),
				'_wpnonce'             => wp_create_nonce( self::NONCE_ACTION ),
			),
			home_url( '/' )
		);
	}

	public function handle(): void {
		if ( ! isset( $_GET['swf_template_preview'] ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to preview this template.', 'sw-forms' ), 403 );
		}
		$template_id = sanitize_text_field( wp_unslash( $_GET['swf_template_preview'] ) );
		$item        = Templates::get( $template_id );
		$document    = null === $item ? null : ( is_array( $item['document'] ?? null ) ? $item['document'] : null );
		if ( null === $document ) {
			wp_die( esc_html__( 'This template does not exist.', 'sw-forms' ), 404 );
		}
		nocache_headers();
		show_admin_bar( false );
		Assets::enqueue();
		$normalized = ( new DocumentNormalizer( FieldRegistry::instance() ) )->normalize( $document );
		$html       = FormView::render_demo( self::demo_id( $template_id ), $normalized, (string) ( $item['title'] ?? '' ) );

		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( (string) ( $item['title'] ?? __( 'Template preview', 'sw-forms' ) ) ) . '</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core function returns escaped attributes.
		wp_head();
		echo '<style>' . self::CSS . '</style></head><body class="swf-template-preview"><p class="swf-template-preview__note"><strong>' . esc_html__( 'Preview', 'sw-forms' ) . '</strong> ' . esc_html__( 'This is the real form. Try it out, including conditions and validation – nothing you enter is sent or stored.', 'sw-forms' ) . '</p>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the field types.
		wp_footer();
		echo '</body></html>';
		exit;
	}

	/**
	 * Stable positive id for the demo form; it never addresses a stored form.
	 */
	private static function demo_id( string $template_id ): int {
		return max( 1, crc32( $template_id ) & 0x7FFFFFFF );
	}

	/** Chrome only; the form itself uses the regular frontend stylesheet. The page has no theme CSS, so set a base font the form inherits. */
	private const CSS = 'body.swf-template-preview{margin:0;padding:1rem;background:#f5f6f8;color:#1f2937;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}'
		. '.swf-template-preview__note{max-width:760px;margin:0 auto .75rem;padding:.6rem .8rem;font:14px/1.5 system-ui,sans-serif;border-radius:8px;color:#1f2937;background:#e8eefb}'
		. '.swf-template-preview>.swf{max-width:760px;margin:0 auto}';
}
