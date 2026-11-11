<?php
/**
 * Thin WordPress policy adapter. No state reads or network activity on frontend requests.
 *
 * @package SWF
 */

declare( strict_types=1 );

/**
 * Internal verified update component.
 *
 * @since 0.5.0
 */
final class SWF_Update_Provider {
	public static function init(): void {
		add_filter( 'auto_update_plugin', array( self::class, 'automatic' ), PHP_INT_MAX, 2 );
		add_filter( 'plugin_auto_update_setting_html', array( self::class, 'automatic_html' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( self::class, 'download_guard' ), PHP_INT_MAX, 4 );
		add_filter( 'site_transient_update_plugins', array( self::class, 'offers' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'offers' ) );
		add_filter( 'update_plugins_github.com', array( self::class, 'update_uri' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'migrate' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_ajax_swf_check_github_updates', array( self::class, 'ajax_check' ) );
	}

	/**
	 * @return array<string,array{slug:string,version:string}>
	 */
	public static function descriptors(): array {
		return (array) apply_filters( 'swf_verified_update_descriptors', require __DIR__ . '/descriptors.php' );
	}

	private static function uri(): string {
		$source = require __DIR__ . '/source.php';
		return 'https://github.com/' . $source['repository'];
	}

	private static function load(): void {
		require_once __DIR__ . '/class-sw-forms-update-service.php';
	}

	/**
	 * @param mixed  $allowed
	 * @param object $item
	 * @return mixed
	 */
	public static function automatic( mixed $allowed, object $item ): mixed {
		return isset( self::descriptors()[ $item->plugin ?? '' ] ) ? false : $allowed;
	}

	public static function automatic_html( string $html, string $file ): string {
		return isset( self::descriptors()[ $file ] ) ? esc_html__( 'Manual updates only', 'sw-forms' ) : $html;
	}

	/**
	 * @param mixed               $reply
	 * @param string              $url Expected package URL.
	 * @param mixed               $upgrader
	 * @param array<string,mixed> $extra
	 * @return mixed
	 */
	public static function download_guard( mixed $reply, string $url, mixed $upgrader, array $extra = array() ): mixed {
		$descriptor = self::descriptors()[ $extra['plugin'] ?? '' ] ?? null;
		// Also catch a known provider URL when hook_extra is missing or was tampered with.
		$provider_url = str_starts_with( $url, self::uri() . '/releases/download/' );
		if ( null === $descriptor && ! $provider_url ) {
			return $reply;
		}
		if ( null === $descriptor || ! is_admin() || wp_doing_cron() || ! current_user_can( 'update_plugins' ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return new WP_Error( 'manual_update_required', __( 'GitHub updates can only be installed manually in the WordPress backend.', 'sw-forms' ) );
		}
		self::load();
		if ( ! SWF_Update_Service::interactive() || ( isset( $upgrader->skin ) && is_a( $upgrader->skin, 'Automatic_Upgrader_Skin' ) && ! is_a( $upgrader->skin, 'WP_Ajax_Upgrader_Skin' ) ) ) {
			return new WP_Error( 'manual_update_required', __( 'Automatic GitHub installation is blocked.', 'sw-forms' ) );
		}
		// Core authenticated the native single/bulk/updates-AJAX action before invoking its upgrader.
		$action        = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		$nonce_actions = array(
			'upgrade-plugin'    => 'upgrade-plugin_' . $extra['plugin'],
			'update-plugin'     => 'updates',
			'update-selected'   => 'bulk-update-plugins',
			'do-plugin-upgrade' => 'upgrade-core',
		);
		$nonce         = '';
		if ( isset( $_REQUEST['_ajax_nonce'] ) && is_string( $_REQUEST['_ajax_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_ajax_nonce'] ) );
		} elseif ( isset( $_REQUEST['_wpnonce'] ) && is_string( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		}
		if ( ! isset( $nonce_actions[ $action ] ) || ! wp_verify_nonce( $nonce, $nonce_actions[ $action ] ) ) {
			return new WP_Error( 'manual_update_required', __( 'No valid manual WordPress update request.', 'sw-forms' ) );
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = WP_PLUGIN_DIR . '/' . $extra['plugin'];
		if ( is_file( $installed ) ) {
			$descriptor['version'] = get_plugin_data( $installed, false, false )['Version'];
		}
		return SWF_Update_Service::download( $url, $descriptor );
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public static function offers( mixed $transient ): mixed {
		if ( ! is_admin() || wp_doing_cron() || ! current_user_can( 'update_plugins' ) || ! $transient instanceof stdClass ) {
			return $transient;
		}
		self::load();
		$state = SWF_Update_Store::read();
		foreach ( self::descriptors() as $file => $descriptor ) {
			if ( ! isset( $transient->checked[ $file ] ) ) {
				continue;
			}
			unset( $transient->response[ $file ], $transient->no_update[ $file ] );
			if ( empty( $state['last_success'] ) || time() - $state['last_success'] > 7 * DAY_IN_SECONDS || empty( $state['manifest'] ) || ! SWF_Update_Service::keys() ) {
				continue;
			}
			$package = SWF_Update_Manifest::package( $state['manifest'], $descriptor['slug'] );
			if ( null === $package || ! SWF_Update_Service::compatible( $package ) ) {
				continue;
			}
			$offer                         = (object) array(
				'id'           => self::uri(),
				'slug'         => $descriptor['slug'],
				'plugin'       => $file,
				'new_version'  => $package['version'],
				'version'      => $package['version'],
				'url'          => self::uri() . '/releases',
				'package'      => SWF_Update_Manifest::url( $state['manifest'], $package ),
				'requires'     => $package['requires_wp'],
				'requires_php' => $package['requires_php'],
				'autoupdate'   => false,
			);
			$bucket                        = version_compare( $package['version'], $transient->checked[ $file ], '>' ) ? 'response' : 'no_update';
			$transient->{$bucket}[ $file ] = $offer;
		}
		return $transient;
	}

	/**
	 * @param mixed               $reply
	 * @param array<string,mixed> $data
	 * @return mixed
	 */
	public static function update_uri( mixed $reply, array $data, string $file ): mixed {
		if ( ! isset( self::descriptors()[ $file ] ) || ( $data['UpdateURI'] ?? '' ) !== self::uri() ) {
			return $reply;
		}
		$transient = self::offers(
			(object) array(
				'checked'   => array( $file => $data['Version'] ),
				'response'  => array(),
				'no_update' => array(),
			)
		);
		return $transient->response[ $file ] ?? $transient->no_update[ $file ] ?? false;
	}

	/**
	 * @param mixed  $reply
	 * @param string $action API action.
	 * @param mixed  $args
	 * @return mixed
	 */
	public static function details( mixed $reply, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return $reply;
		}
		foreach ( self::descriptors() as $file => $descriptor ) {
			if ( ( $args->slug ?? '' ) !== $descriptor['slug'] ) {
				continue;
			}
			$offers = self::offers( (object) array( 'checked' => array( $file => $descriptor['version'] ) ) );
			$offer  = $offers->response[ $file ] ?? $offers->no_update[ $file ] ?? null;
			if ( null !== $offer ) {
				return (object) array(
					'name'          => $descriptor['slug'],
					'slug'          => $descriptor['slug'],
					'version'       => $offer->new_version,
					'requires'      => $offer->requires,
					'requires_php'  => $offer->requires_php,
					'download_link' => $offer->package,
					'sections'      => array( 'description' => esc_html__( 'Signed GitHub release. Install only manually through WordPress.', 'sw-forms' ) ),
				);
			}
		}
		return $reply;
	}

	public static function ajax_check(): void {
		check_ajax_referer( 'swf_verified_updates', 'nonce' );
		self::load();
		$manual = isset( $_POST['manual'] ) && '1' === $_POST['manual'];
		$result = SWF_Update_Service::check( $manual );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Check button and status in the plugin's own row of the native plugin list.
	 *
	 * @param array<int|string,string> $meta Row meta links.
	 * @param string                   $file Plugin basename.
	 * @return array<int|string,string>
	 */
	public static function row_meta( array $meta, string $file ): array {
		if ( ! isset( self::descriptors()[ $file ] ) || ! is_admin() ) {
			return $meta;
		}
		self::load();
		if ( ! SWF_Update_Service::interactive() ) {
			return $meta;
		}
		$meta[] = '<button type="button" class="button-link" id="swf-verified-updates-check">' . esc_html__( 'Check for updates now', 'sw-forms' ) . '</button>'
			. ' <span id="swf-verified-updates-result" role="status" aria-live="polite" hidden></span>'
			. ' <a id="swf-verified-updates-reload" href="' . esc_url( is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) ) . '" hidden>' . esc_html__( 'Reload the plugin list to show available updates', 'sw-forms' ) . '</a>'
			. ' <span id="swf-verified-updates-status">' . esc_html( self::status_text() ) . '</span>';
		return $meta;
	}

	/**
	 * Human readable state from the local cache (no request).
	 */
	public static function status_text(): string {
		self::load();
		if ( ! SWF_Update_Service::keys() ) {
			return __( 'GitHub updates are blocked: no public release key is configured.', 'sw-forms' );
		}
		$state = SWF_Update_Store::read();
		if ( empty( $state['last_success'] ) ) {
			$text = __( 'No verified update check yet.', 'sw-forms' );
		} else {
			/* translators: %s: time span, e.g. "2 hours" */
			$text = sprintf( __( 'Last verified check: %s ago.', 'sw-forms' ), human_time_diff( (int) $state['last_success'] ) );
		}
		if ( ! empty( $state['error'] ) && is_string( $state['error'] ) ) {
			/* translators: %s: error message */
			$text .= ' ' . sprintf( __( 'Last error: %s', 'sw-forms' ), $state['error'] );
		}
		return $text;
	}

	public static function enqueue(): void {
		self::load();
		if ( ! SWF_Update_Service::interactive() ) {
			return;
		}
		$state = SWF_Update_Store::read();
		wp_enqueue_script( 'swf-verified-updates', SWF_URL . 'assets/js/sw-forms-updates.js', array(), SWF_VERSION, true );
		wp_add_inline_script(
			'swf-verified-updates',
			'window.swfVerifiedUpdates=' . wp_json_encode(
				array(
					'checking'  => __( 'Checking signed releases…', 'sw-forms' ),
					'completed' => __( 'Update check completed.', 'sw-forms' ),
					'failed'    => __( 'GitHub could not be reached. Please try again later.', 'sw-forms' ),
					'url'       => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'swf_verified_updates' ),
					'due'       => (bool) SWF_Update_Service::keys() && time() - ( $state['last_attempt'] ?? 0 ) >= DAY_IN_SECONDS,
				)
			) . ';',
			'before'
		);
	}

	/** SW Forms had no legacy updater; remove only its native automatic selection/offers. */
	public static function migrate(): void {
		if ( ! current_user_can( 'update_plugins' ) || get_option( 'swf_verified_updates_migrated', false ) ) {
			return;
		}
		self::clear_automatic_selection();
		$transient = get_site_transient( 'update_plugins' );
		if ( $transient instanceof stdClass ) {
			foreach ( array_keys( self::descriptors() ) as $file ) {
				unset( $transient->response[ $file ], $transient->no_update[ $file ] );
			}
			set_site_transient( 'update_plugins', $transient );
		}
		update_option( 'swf_verified_updates_migrated', 1, false );
	}

	private static function clear_automatic_selection(): void {
		$selected = get_site_option( 'auto_update_plugins', array() );
		if ( is_array( $selected ) ) {
			update_site_option( 'auto_update_plugins', array_values( array_diff( $selected, array_keys( self::descriptors() ) ) ) );
		}
	}

	/** Remove executable cached offers before this plugin's guards stop loading. */
	public static function deactivate(): void {
		self::clear_automatic_selection();
		remove_filter( 'site_transient_update_plugins', array( self::class, 'offers' ) );
		remove_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'offers' ) );
		$transient = get_site_transient( 'update_plugins' );
		if ( $transient instanceof stdClass ) {
			foreach ( array_keys( self::descriptors() ) as $file ) {
				unset( $transient->response[ $file ], $transient->no_update[ $file ] );
			}
			set_site_transient( 'update_plugins', $transient );
		}
	}
}
SWF_Update_Provider::init();
