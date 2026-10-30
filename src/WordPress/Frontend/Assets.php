<?php
/**
 * Frontend script and stylesheet (built from apps/admin/frontend into assets/frontend).
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\WordPress\Support\Settings;

final class Assets {

	public const HANDLE = 'sw-forms';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_early' ), 20 );
	}

	public function register_assets(): void {
		$dir = SWF_DIR . 'assets/frontend/';
		if ( is_readable( $dir . 'form.css' ) ) {
			wp_register_style( self::HANDLE, SWF_URL . 'assets/frontend/form.css', array(), (string) filemtime( $dir . 'form.css' ) );
		}
		if ( is_readable( $dir . 'form.js' ) ) {
			wp_register_script(
				self::HANDLE,
				SWF_URL . 'assets/frontend/form.js',
				array(),
				(string) filemtime( $dir . 'form.js' ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/** Loads the stylesheet in the head when the current post contains the shortcode (avoids a flash of unstyled form). */
	public function enqueue_early(): void {
		$post = get_post();
		if ( is_singular() && $post instanceof \WP_Post && ( has_shortcode( $post->post_content, Shortcode::TAG ) || has_block( 'swforms/form', $post ) ) ) {
			self::enqueue();
		}
	}

	public static function enqueue(): void {
		if ( ! did_action( 'wp_enqueue_scripts' ) && ! doing_action( 'wp_enqueue_scripts' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 30 );
			return;
		}
		if ( Settings::all()['load_css'] ) {
			wp_enqueue_style( self::HANDLE );
		}
		wp_enqueue_script( self::HANDLE );
	}
}
