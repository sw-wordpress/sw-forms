<?php
/**
 * WordPress admin integration: menu page hosting the Vue/Quasar single page application.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Admin;

use SWForms\WordPress\Support\Capabilities;

final class AdminPage {

	public const SLUG    = 'sw-forms';
	private const HANDLE = 'sw-forms-admin';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );
	}

	public function add_menu(): void {
		$capability = current_user_can( Capabilities::MANAGE_FORMS ) ? Capabilities::MANAGE_FORMS : Capabilities::VIEW_ENTRIES;
		$this->hook = (string) add_menu_page(
			__( 'SW Forms', 'sw-forms' ),
			__( 'SW Forms', 'sw-forms' ),
			$capability,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-feedback',
			58
		);
	}

	public function render(): void {
		if ( ! Capabilities::can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-forms' ), 403 );
		}
		if ( ! is_readable( SWF_DIR . 'assets/admin/app.js' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'The SW Forms admin interface has not been built. Install the release package or run "npm run build" in apps/admin.', 'sw-forms' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap swf-wrap"><div id="sw-forms-app"></div><noscript>' . esc_html__( 'SW Forms requires JavaScript.', 'sw-forms' ) . '</noscript></div>';
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook || ! is_readable( SWF_DIR . 'assets/admin/app.js' ) ) {
			return;
		}
		$version = (string) filemtime( SWF_DIR . 'assets/admin/app.js' );
		wp_enqueue_style( self::HANDLE, SWF_URL . 'assets/admin/app.css', array(), $version );
		wp_enqueue_script( 'sw-forms-builder-api', SWF_URL . 'assets/builder-api.js', array(), SWF_VERSION, true );
		wp_enqueue_script( self::HANDLE, SWF_URL . 'assets/admin/app.js', array( 'sw-forms-builder-api' ), $version, true );
		foreach ( \SWForms\WordPress\Fields\FieldRegistry::instance()->all() as $type ) {
			if ( $type instanceof \SWForms\WordPress\Fields\BuilderFieldTypeInterface && wp_script_is( $type->builder_script(), 'registered' ) ) {
				wp_enqueue_script( $type->builder_script() );
			}
		}
		wp_add_inline_script( self::HANDLE, 'window.swFormsConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Configuration contract with the SPA (apps/admin/src/types/config.ts).
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array(
			'restRoot'     => esc_url_raw( rest_url( 'swforms/v1/' ) ),
			'wpRestRoot'   => esc_url_raw( rest_url( 'wp/v2/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'locale'       => determine_locale(),
			'version'      => SWF_VERSION,
			'adminUrl'     => esc_url_raw( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'capabilities' => Capabilities::current_user_caps(),
			'siteUrl'      => esc_url_raw( home_url( '/' ) ),
		);
	}

	public function module_tag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}
}
