<?php
/**
 * Interactive developer/design preview of all form design variants:
 * `/?swf_design_gallery=1&_wpnonce=...`. Form managers only; demo forms never submit data.
 *
 * @since 0.6.0
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\DocumentNormalizer;
use SWForms\WordPress\Support\Capabilities;

final class DesignGallery {

	public const NONCE_ACTION = 'swf_design_gallery';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), -1 );
	}

	public static function url(): string {
		return add_query_arg(
			array(
				'swf_design_gallery' => 1,
				'_wpnonce'           => wp_create_nonce( self::NONCE_ACTION ),
			),
			home_url( '/' )
		);
	}

	public function handle(): void {
		if ( ! isset( $_GET['swf_design_gallery'] ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to view the design gallery.', 'sw-forms' ), 403 );
		}
		nocache_headers();
		show_admin_bar( false );
		Assets::enqueue();
		$body = self::markup();
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__( 'Form design gallery', 'sw-forms' ) . '</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core function returns escaped attributes.
		wp_head();
		echo '<style>' . self::CSS . '</style></head><body class="swfg-body">' . $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS; markup escaped while it is built.
		wp_footer();
		wp_print_inline_script_tag( self::SCRIPT );
		echo '</body></html>';
		exit;
	}

	/**
	 * Display names and short descriptions of the variants.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function variants(): array {
		return array(
			'classic'         => array( __( 'Classic', 'sw-forms' ), __( 'The original, theme friendly look.', 'sw-forms' ) ),
			'floating'        => array( __( 'Floating labels', 'sw-forms' ), __( 'Light SaaS style; labels move to the top inside the field.', 'sw-forms' ) ),
			'minimal'         => array( __( 'Minimal lines', 'sw-forms' ), __( 'Underlined fields, generous white space, no motion.', 'sw-forms' ) ),
			'dark-focus'      => array( __( 'Dark focus', 'sw-forms' ), __( 'Dark surfaces with a violet accent and a soft focus glow.', 'sw-forms' ) ),
			'guided-card'     => array( __( 'Guided card', 'sw-forms' ), __( 'A friendly card with section titles and progress.', 'sw-forms' ) ),
			'animated-float'  => array( __( 'Animated float', 'sw-forms' ), __( 'Outlined fields; the label glides into the border.', 'sw-forms' ) ),
			'surprise-motion' => array( __( 'Surprise motion', 'sw-forms' ), __( 'Growing underlines, an animated button and success ticks.', 'sw-forms' ) ),
		);
	}

	/**
	 * The shared reference form ("Personal details") in the given variant.
	 *
	 * @return array<string, mixed>
	 */
	public static function document( string $variant ): array {
		$option = static fn ( string $value, string $label ): array => array(
			'value' => $value,
			'label' => $label,
		);
		return array(
			'schemaVersion' => 1,
			'fields'        => array(
				array(
					'id'       => 'salutation',
					'type'     => 'select',
					'key'      => 'salutation',
					'label'    => __( 'Salutation', 'sw-forms' ),
					'settings' => array(
						'options'     => array( $option( 'ms', __( 'Ms', 'sw-forms' ) ), $option( 'mr', __( 'Mr', 'sw-forms' ) ), $option( 'none', __( 'Not specified', 'sw-forms' ) ) ),
						'placeholder' => '',
					),
				),
				array(
					'id'       => 'columns',
					'type'     => 'columns',
					'settings' => array( 'stack' => 'sm' ),
					'columns'  => array(
						array(
							'id'       => 'c1',
							'width'    => 6,
							'children' => array( self::input( 'first_name', __( 'First name', 'sw-forms' ), 'given-name', true ) ),
						),
						array(
							'id'       => 'c2',
							'width'    => 6,
							'children' => array( self::input( 'last_name', __( 'Last name', 'sw-forms' ), 'family-name', true ) ),
						),
					),
				),
				self::input( 'title', __( 'Title', 'sw-forms' ), 'honorific-prefix', false ) + array( 'description' => __( 'Optional, e.g. Dr.', 'sw-forms' ) ),
			),
			'settings'      => array(
				'submitLabel' => __( 'Continue', 'sw-forms' ),
				'design'      => array( 'variant' => $variant ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function input( string $id, string $label, string $autocomplete, bool $required ): array {
		return array(
			'id'       => $id,
			'type'     => 'input',
			'key'      => $id,
			'label'    => $label,
			'required' => $required,
			'settings' => array(
				'inputType'    => 'text',
				'autocomplete' => $autocomplete,
			),
		);
	}

	public static function markup(): string {
		$filters    = '<label><input type="radio" name="swfg-variant" value="" checked> ' . esc_html__( 'All', 'sw-forms' ) . '</label>';
		$cards      = '';
		$index      = 0;
		$normalizer = new DocumentNormalizer( FieldRegistry::instance() );
		foreach ( self::variants() as $variant => [ $name, $description ] ) {
			++$index;
			$filters .= '<label><input type="radio" name="swfg-variant" value="' . esc_attr( $variant ) . '"> ' . esc_html( $name ) . '</label>';
			$cards   .= '<section class="swfg-card" data-swfg-variant="' . esc_attr( $variant ) . '" aria-labelledby="swfg-' . esc_attr( $variant ) . '">'
				. '<header class="swfg-card__head"><h2 id="swfg-' . esc_attr( $variant ) . '">' . esc_html( $name ) . '</h2><p>' . esc_html( $description ) . '</p><code>variant="' . esc_html( $variant ) . '"</code></header>'
				. FormView::render_demo( $index, $normalizer->normalize( self::document( $variant ) ), __( 'Personal details', 'sw-forms' ) )
				. '</section>';
		}
		$schemes = '';
		foreach ( array(
			'light' => __( 'Light', 'sw-forms' ),
			'dark'  => __( 'Dark', 'sw-forms' ),
			'auto'  => __( 'System', 'sw-forms' ),
		) as $scheme => $label ) {
			$schemes .= '<label><input type="radio" name="swfg-scheme" value="' . esc_attr( $scheme ) . '"' . ( 'light' === $scheme ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
		}
		return '<main class="swfg"><header class="swfg-head"><h1>' . esc_html__( 'Form design gallery', 'sw-forms' ) . '</h1>'
			. '<p>' . esc_html__( 'Try every variant with the same fields. Nothing you enter here is sent or stored.', 'sw-forms' ) . '</p>'
			. '<div class="swfg-tools">'
			. '<fieldset><legend>' . esc_html__( 'Variant', 'sw-forms' ) . '</legend>' . $filters . '</fieldset>'
			. '<fieldset><legend>' . esc_html__( 'Colour scheme', 'sw-forms' ) . '</legend>' . $schemes . '</fieldset>'
			. '<div class="swfg-actions"><button type="button" data-swfg="fill">' . esc_html__( 'Fill with sample data', 'sw-forms' ) . '</button>'
			. '<button type="button" data-swfg="validate">' . esc_html__( 'Show validation', 'sw-forms' ) . '</button>'
			. '<button type="button" data-swfg="reset">' . esc_html__( 'Reset', 'sw-forms' ) . '</button></div>'
			. '</div></header><div class="swfg-grid">' . $cards . '</div></main>';
	}

	/** Gallery chrome only; the forms use the regular frontend stylesheet. */
	private const CSS = '.swfg-body{margin:0;background:#f3f4f6;color:#111827;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}'
		. '.swfg{max-width:1440px;margin:0 auto;padding:2rem 1rem 4rem}.swfg-head h1{margin:0 0 .25rem;font-size:1.75rem}.swfg-head p{margin:0 0 1rem;color:#4b5563}'
		. '.swfg-tools{display:flex;flex-wrap:wrap;gap:1rem 2rem;align-items:flex-end;margin-bottom:1.5rem}.swfg-tools fieldset{display:flex;flex-wrap:wrap;gap:.25rem .75rem;margin:0;padding:0;border:0}'
		. '.swfg-tools legend{padding:0;margin-bottom:.25rem;font-weight:600;font-size:.875rem}.swfg-tools label{display:inline-flex;gap:.35rem;align-items:center;font-size:.875rem;cursor:pointer}'
		. '.swfg-actions{display:flex;flex-wrap:wrap;gap:.5rem}.swfg-actions button{font:inherit;font-size:.875rem;padding:.45rem .9rem;border:1px solid #9ca3af;border-radius:6px;background:#fff;color:#111827;cursor:pointer}'
		. '.swfg-actions button:focus-visible,.swfg-tools input:focus-visible{outline:2px solid #2563eb;outline-offset:2px}'
		. '.swfg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,22rem),1fr));gap:1.5rem}.swfg-grid--single{grid-template-columns:minmax(0,46rem);justify-content:center}'
		. '.swfg-card{display:flex;flex-direction:column;gap:1rem;padding:1.25rem;background:#fff;border:1px solid #e5e7eb;border-radius:12px}.swfg-card[hidden]{display:none}'
		. '.swfg-card__head h2{margin:0;font-size:1.125rem}.swfg-card__head p{margin:.15rem 0 .35rem;color:#4b5563;font-size:.875rem}.swfg-card__head code{font-size:.75rem;color:#374151;background:#f3f4f6;padding:.1rem .35rem;border-radius:4px}'
		. '@media (prefers-color-scheme:dark){.swfg-body{background:#0b0d12;color:#e5e7eb}.swfg-head p,.swfg-card__head p{color:#9ca3af}.swfg-card{background:#151821;border-color:#262a35}.swfg-card__head code{background:#262a35;color:#d1d5db}.swfg-actions button{background:#1f2330;color:#e5e7eb;border-color:#4b5563}}';

	/** Variant filter, colour scheme switch and sample interactions (no network). */
	private const SCRIPT = '(function(){var d=document,g=d.querySelector(".swfg-grid");'
		. 'd.addEventListener("change",function(e){var t=e.target;if(!t||!t.name)return;'
		. 'if(t.name==="swfg-variant"){d.querySelectorAll(".swfg-card").forEach(function(c){c.hidden=t.value!==""&&c.dataset.swfgVariant!==t.value;});g.classList.toggle("swfg-grid--single",t.value!=="");}'
		. 'if(t.name==="swfg-scheme"){d.querySelectorAll(".swfg .swf").forEach(function(w){w.classList.remove("swf-scheme--light","swf-scheme--dark","swf-scheme--auto");w.classList.add("swf-scheme--"+t.value);});}});'
		. 'd.addEventListener("click",function(e){var b=e.target&&e.target.closest("[data-swfg]");if(!b)return;var a=b.getAttribute("data-swfg");'
		. 'd.querySelectorAll(".swfg form.swf-form").forEach(function(f){if(a==="reset"){f.reset();return;}if(a==="validate"){f.requestSubmit();return;}'
		. 'var v={salutation:"ms",first_name:"Erika",last_name:"Mustermann",title:"Dr."};Object.keys(v).forEach(function(k){var c=f.querySelector("[name=\"swf["+k+"]\"]");if(c){c.value=v[k];c.dispatchEvent(new Event("change",{bubbles:true}));}});});});})();';
}
