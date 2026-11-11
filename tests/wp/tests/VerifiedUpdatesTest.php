<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress PHPUnit conventions.

/** End-to-end signed releases with isolated HTTP and disposable signing keys. */
final class VerifiedUpdatesTest extends SWF_Update_TestCase
{
    private string $secret;
    private array $keys;
    private array $release;
    private array $manifest;
    private string $raw;
    private string $signature;
    private string $zip;
    private array $requests = array();

    public function set_up(): void
    {
        parent::set_up();
        if (is_multisite()) {
            grant_super_admin($this->admin_id);
        }
        set_current_screen(is_multisite() ? 'plugins-network' : 'plugins');
        require_once SWF_DIR . 'includes/update/class-sw-forms-update-service.php';
        delete_site_option(SWF_Update_Store::STATE);
        delete_site_option(SWF_Update_Store::LOCK);
        $keypair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($keypair);
        $this->keys = array( 'test' => base64_encode(sodium_crypto_sign_publickey($keypair)) );
        add_filter('swf_verified_release_public_keys', fn () => $this->keys);
        $this->zip = wp_tempnam('swf-signed-test.zip');
        $archive = new ZipArchive();
        $archive->open($this->zip, ZipArchive::OVERWRITE);
        $archive->addFromString('sw-forms/sw-forms.php', "<?php\n/**\n * Plugin Name: Signed test plugin\n * Version: 9.0.0.0\n * Requires PHP: 8.2\n * Requires at least: 6.0\n */\n");
        $archive->close();
        $package = array( 'slug' => 'sw-forms', 'version' => '9.0.0.0', 'asset_id' => 55, 'filename' => 'sw-forms-9.0.0.0.zip', 'bytes' => filesize($this->zip), 'sha256' => hash_file('sha256', $this->zip), 'requires_php' => '8.2', 'requires_wp' => '6.0', 'main_file' => 'sw-forms.php' );
        $this->release = array( 'id' => 42, 'tag_name' => 'v9.0.0.0', 'draft' => false, 'prerelease' => false, 'assets' => array( array( 'id' => 55, 'name' => $package['filename'], 'size' => $package['bytes'] ), array( 'id' => 56, 'name' => 'sw-plugin-release.json' ), array( 'id' => 57, 'name' => 'sw-plugin-release.json.sig' ) ) );
        $this->manifest = array( 'schema' => 1, 'repository' => SWF_Update_Manifest::source()['repository'], 'repository_id' => SWF_Update_Manifest::source()['repository_id'], 'release_id' => 42, 'tag' => 'v9.0.0.0', 'commit' => str_repeat('a', 40), 'issued_at' => time(), 'packages' => array( $package ) );
        $this->sign();
        remove_all_filters('pre_http_request');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->requests[] = $url;
            if (str_contains($url, 'api.wordpress.org/')) {
                $body = wp_json_encode(array( 'plugins' => array(), 'themes' => array(), 'no_update' => array(), 'translations' => array() ));
            } elseif (! empty($args['stream'])) {
                copy($this->zip, $args['filename']);
                $body = '';
            } elseif (str_ends_with($url, '/releases/latest')) {
                $body = wp_json_encode($this->release);
            } elseif (str_ends_with($url, '.json.sig')) {
                $body = $this->signature;
            } else {
                $body = $this->raw;
            }
            return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200 ) );
        }, 10, 3);
    }

    private function sign(): void
    {
        $this->raw = wp_json_encode($this->manifest);
        $this->signature = wp_json_encode(array( 'key_id' => 'test', 'signature' => base64_encode(sodium_crypto_sign_detached($this->raw, $this->secret)) ));
    }

    public function tear_down(): void
    {
        if (is_multisite()) {
            revoke_super_admin($this->admin_id);
        }
        wp_delete_file($this->zip);
        set_current_screen('front');
        parent::tear_down();
    }

    public function test_signed_release_is_cached_and_displayed_without_additional_http(): void
    {
        $this->assertIsArray(SWF_Update_Service::check());
        $this->assertCount(3, $this->requests);
        $file = array_key_first(SWF_Update_Provider::descriptors());
        $offers = SWF_Update_Provider::offers((object) array( 'checked' => array( $file => SWF_VERSION ) ));
        $this->assertSame('9.0.0.0', $offers->response[ $file ]->new_version);
        $this->assertFalse($offers->response[ $file ]->autoupdate);
        $this->assertCount(3, $this->requests);
        $this->assertWPError(SWF_Update_Service::check());
        delete_site_transient('update_plugins');
        $this->assertWPError(SWF_Update_Service::check(true));
        $this->assertCount(3, $this->requests);
    }

    public function test_failed_requests_are_throttled_and_manual_check_can_override_daily_limit(): void
    {
        $this->release['draft'] = true;
        $this->assertWPError(SWF_Update_Service::check());
        $this->assertWPError(SWF_Update_Service::check());
        $this->assertCount(1, $this->requests);
        $state = SWF_Update_Store::read();
        $state['last_attempt'] = time() - 61;
        SWF_Update_Store::write($state);
        $this->release['draft'] = false;
        $this->assertIsArray(SWF_Update_Service::check(true));
        $this->assertCount(4, $this->requests);
    }

    public function test_frontend_and_unprivileged_users_never_check_or_load_remote_offers(): void
    {
        set_current_screen('front');
        $this->assertWPError(SWF_Update_Service::check(true));
        $value = (object) array( 'checked' => array() );
        $this->assertSame($value, SWF_Update_Provider::offers($value));
        set_current_screen(is_multisite() ? 'plugins-network' : 'plugins');
        wp_set_current_user(self::factory()->user->create(array( 'role' => 'subscriber' )));
        $this->assertWPError(SWF_Update_Service::check(true));
        $this->assertCount(0, $this->requests);
    }

    public function test_unknown_key_and_modified_signature_fail_closed(): void
    {
        $this->keys = array();
        $this->assertWPError(SWF_Update_Service::check());
        $this->assertCount(0, $this->requests);
        $this->keys = array( 'test' => base64_encode(random_bytes(32)) );
        $this->assertWPError(SWF_Update_Service::check());
        $this->assertArrayNotHasKey('manifest', SWF_Update_Store::read());
    }

    /** @dataProvider invalid_manifests */
    public function test_signed_but_invalid_metadata_is_rejected(string $field, $value): void
    {
        $this->manifest[ $field ] = $value;
        $this->sign();
        $this->assertWPError(SWF_Update_Service::check());
    }
    public static function invalid_manifests(): array
    {
        return array( 'repository' => array( 'repository', 'attacker/repo' ), 'repository ID' => array( 'repository_id', 1 ), 'release ID' => array( 'release_id', 1 ), 'schema' => array( 'schema', 2 ), 'future' => array( 'issued_at', time() + 3600 ), 'duplicates' => array( 'packages', array() ) );
    }

    public function test_even_validly_signed_duplicate_json_keys_are_rejected(): void
    {
        $this->raw = str_replace('"schema":1', '"schema":2,"schema":1', $this->raw);
        $this->signature = wp_json_encode(array( 'key_id' => 'test', 'signature' => base64_encode(sodium_crypto_sign_detached($this->raw, $this->secret)) ));
        $this->assertWPError(SWF_Update_Service::check());
    }

    public function test_lock_owner_cannot_release_another_request_lock(): void
    {
        $lock = SWF_Update_Store::acquire();
        $this->assertIsArray($lock);
        $this->assertFalse(SWF_Update_Store::acquire());
        SWF_Update_Store::release(array( 'token' => 'wrong', 'expires' => 0 ));
        $this->assertFalse(SWF_Update_Store::acquire());
        SWF_Update_Store::release($lock);
        $this->assertIsArray($next = SWF_Update_Store::acquire());
        SWF_Update_Store::release($lock);
        $this->assertSame($next, get_site_option(SWF_Update_Store::LOCK));
    }

    public function test_core_download_boundary_returns_only_verified_zip_and_rejects_tampered_urls(): void
    {
        $this->assertIsArray(SWF_Update_Service::check());
        $file = array_key_first(SWF_Update_Provider::descriptors());
        $_REQUEST['action'] = 'upgrade-plugin';
        $_REQUEST['_wpnonce'] = wp_create_nonce('upgrade-plugin_' . $file);
        $url = SWF_Update_Manifest::url($this->manifest, $this->manifest['packages'][0]);
        $download = apply_filters('upgrader_pre_download', false, $url, new stdClass(), array( 'plugin' => $file ));
        $this->assertIsString($download);
        $this->assertSame($this->manifest['packages'][0]['sha256'], hash_file('sha256', $download));
        wp_delete_file($download);
        $this->assertWPError(apply_filters('upgrader_pre_download', false, 'https://evil.invalid/a.zip', new stdClass(), array( 'plugin' => $file )));
        $_REQUEST['action'] = 'other-action';
        $this->assertWPError(apply_filters('upgrader_pre_download', false, $url, new stdClass(), array( 'plugin' => $file )));
        $this->assertFalse(apply_filters('auto_update_plugin', true, (object) array( 'plugin' => $file )));
        $this->assertTrue(apply_filters('auto_update_plugin', true, (object) array( 'plugin' => 'other/other.php' )));
    }

    /** @dataProvider unsafe_entries */
    public function test_zip_paths_are_rejected_even_with_a_matching_hash(string $entry, bool $symlink = false): void
    {
        $zip = new ZipArchive();
        $zip->open($this->zip);
        $zip->addFromString($entry, 'outside');
        if ($symlink) {
            $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        $zip->close();
        $package = $this->manifest['packages'][0];
        $package['bytes'] = filesize($this->zip);
        $package['sha256'] = hash_file('sha256', $this->zip);
        $this->assertWPError(SWF_Update_Package::verify($this->zip, $package));
    }
    public static function unsafe_entries(): array
    {
        return array( array( 'sw-forms/../outside.php' ), array( '/absolute.php' ), array( 'sw-forms\\outside.php' ), array( 'sw-forms/C:/outside' ), array( 'sw-forms/link', true ), array( 'Sw-forms/SW-FORMS.PHP' ) );
    }

    public function test_missing_key_does_not_leave_legacy_auto_update_controls(): void
    {
        $this->keys = array();
        $html = implode(' ', SWF_Update_Provider::row_meta([], array_key_first(SWF_Update_Provider::descriptors())));
        $this->assertStringNotContainsString('swf_auto_update_enabled', $html);
        $this->assertStringContainsString('GitHub updates are blocked', $html);
    }
    public function test_frontend_filters_add_no_database_queries_and_cron_cannot_download(): void
    {
        set_current_screen('front');
        $value = (object) array( 'checked' => array() );
        $before = get_num_queries();
        $this->assertSame($value, SWF_Update_Provider::offers($value));
        $this->assertSame($before, get_num_queries());
        set_current_screen(is_multisite() ? 'plugins-network' : 'plugins');
        add_filter('wp_doing_cron', '__return_true');
        $this->assertWPError(SWF_Update_Service::check(true));
        $this->assertCount(0, $this->requests);
    }

    public function test_expired_snapshot_and_corrupt_zip_are_rejected_before_core_installation(): void
    {
        $this->assertIsArray(SWF_Update_Service::check());
        $state = SWF_Update_Store::read();
        $state['last_success'] = time() - 8 * DAY_IN_SECONDS;
        SWF_Update_Store::write($state);
        $this->assertWPError(SWF_Update_Service::verified_snapshot());
        $state['last_success'] = time();
        SWF_Update_Store::write($state);
        file_put_contents($this->zip, 'tampered package');
        $descriptors = SWF_Update_Provider::descriptors();
        $descriptor = reset($descriptors);
        $url = SWF_Update_Manifest::url($this->manifest, $this->manifest['packages'][0]);
        $this->assertWPError(SWF_Update_Service::download($url, $descriptor));
    }

    public function test_redireswf_and_size_limits_fail_closed(): void
    {
        remove_all_filters('pre_http_request');
        $seen = array();
        add_filter('pre_http_request', static function ($pre, $args, $url) use (&$seen) {
            $seen[] = $url;
            return array( 'headers' => array( 'location' => 'https://127.0.0.1/private' ), 'body' => '', 'response' => array( 'code' => 302 ) );
        }, 10, 3);
        $this->assertWPError(SWF_Update_Transport::fetch('https://api.github.com/repos/sw-wordpress/sw-forms/releases/latest', 1024));
        $this->assertCount(1, $seen);
        remove_all_filters('pre_http_request');
        add_filter('pre_http_request', static fn () => array( 'headers' => array(), 'body' => str_repeat('a', 1025), 'response' => array( 'code' => 200 ) ));
        $this->assertWPError(SWF_Update_Transport::fetch('https://api.github.com/repos/sw-wordpress/sw-forms/releases/latest', 1024));
    }

    public function test_release_signer_and_runtime_verifier_agree(): void
    {
        $directory = get_temp_dir() . 'swf-sign-' . wp_generate_uuid4();
        wp_mkdir_p($directory);
        $zipfile = $directory . '/' . $this->manifest['packages'][0]['filename'];
        copy($this->zip, $zipfile);
        $metadata = $directory . '/release.json';
        file_put_contents($metadata, wp_json_encode($this->release));
        $process = proc_open(array( PHP_BINARY, SWF_DIR . 'bin/release-manifest.php', 'sign', $metadata, $directory, str_repeat('a', 40) ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, null, array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode($this->secret), 'SW_PLUGIN_RELEASE_KEY_ID' => 'test' ));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        try {
            $this->assertSame(0, $exit, $output);
            $this->assertIsArray(SWF_Update_Manifest::verify(file_get_contents($directory . '/sw-plugin-release.json'), file_get_contents($directory . '/sw-plugin-release.json.sig'), $this->keys, $this->release));
            $this->assertStringNotContainsString(base64_encode($this->secret), $output);
        } finally {
            foreach (glob($directory . '/*') as $file) {
                wp_delete_file($file);
            }
            rmdir($directory);
        }
    }

    /** @dataProvider core_modes */
    public function test_native_wordpress_upgrader_installs_verified_package_only_in_private_wp(string $mode): void
    {
        $this->assertStringStartsWith(SWF_DIR . 'tests/wp/vendor/', realpath(WP_PLUGIN_DIR));
        $root = WP_PLUGIN_DIR . '/sw-forms';
        $this->assertDirectoryDoesNotExist($root);
        wp_mkdir_p($root);
        $buffer_level = ob_get_level();
        ob_start();
        try {
            $file = 'sw-forms/sw-forms.php';
            add_filter('swf_verified_update_descriptors', static fn () => array( $file => array( 'slug' => 'sw-forms', 'version' => '1.0.0.0' ) ));
            file_put_contents($root . '/sw-forms.php', "<?php\n/*\nPlugin Name: Old test plugin\nVersion: 1.0.0.0\n*/\n");
            $this->assertIsArray(SWF_Update_Service::check());
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            WP_Filesystem();
            set_site_transient('update_plugins', (object) array( 'checked' => array( $file => '1.0.0.0' ) ));
            $this->assertArrayHasKey($file, get_site_transient('update_plugins')->response ?? array());
            $bulk = 'bulk' === $mode;
            $_REQUEST['action'] = $bulk ? 'update-selected' : ( 'ajax' === $mode ? 'update-plugin' : 'upgrade-plugin' );
            $_REQUEST['_wpnonce'] = wp_create_nonce($bulk ? 'bulk-update-plugins' : ( 'ajax' === $mode ? 'updates' : 'upgrade-plugin_' . $file ));
            $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
            $result = $bulk ? $upgrader->bulk_upgrade(array( $file ), array( 'clear_update_cache' => false )) : $upgrader->upgrade($file, array( 'clear_update_cache' => false ));
            if ($bulk) {
                $this->assertIsArray($result);
                $this->assertIsArray($result[ $file ]);
                $result = true;
            }
            $this->assertTrue($result, is_wp_error($result) ? $result->get_error_message() : 'Native upgrade failed: ' . $upgrader->skin->get_error_messages());
            $this->assertStringContainsString('Version: 9.0.0.0', file_get_contents($root . '/sw-forms.php'));
        } finally {
            while (ob_get_level() > $buffer_level) {
                ob_end_clean();
            }
            wp_delete_file($root . '/sw-forms.php');
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }
    public static function core_modes(): array
    {
        return array( 'single' => array( 'single' ), 'bulk' => array( 'bulk' ), 'ajax' => array( 'ajax' ) );
    }
    public function test_wordpress_details_and_update_uri_use_only_cached_signed_metadata(): void
    {
        $this->assertIsArray(SWF_Update_Service::check());
        $file = array_key_first(SWF_Update_Provider::descriptors());
        $uri = 'https://github.com/sw-wordpress/sw-forms';
        $offer = SWF_Update_Provider::update_uri(false, array( 'UpdateURI' => $uri, 'Version' => SWF_VERSION ), $file);
        $this->assertSame('9.0.0.0', $offer->new_version);
        $details = SWF_Update_Provider::details(false, 'plugin_information', (object) array( 'slug' => 'sw-forms' ));
        $this->assertSame('9.0.0.0', $details->version);
        $original = (object) array( 'foreign' => true );
        $this->assertSame($original, SWF_Update_Provider::update_uri($original, array( 'UpdateURI' => 'https://evil.invalid', 'Version' => '1' ), $file));
        $this->assertSame($original, SWF_Update_Provider::details($original, 'plugin_information', (object) array( 'slug' => 'other-plugin' )));
        $this->assertCount(3, $this->requests);
        $this->assertSame('Manual updates only', SWF_Update_Provider::automatic_html('', $file));
        $this->assertSame('unchanged', SWF_Update_Provider::automatic_html('unchanged', 'foreign/plugin.php'));
    }

    private function ajax(string $nonce): mixed
    {
        $_POST = array( 'nonce' => $nonce, 'manual' => '1' );
        $_REQUEST = $_POST;
        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', static fn () => static function ($message) {
 echo $message;
throw new SWF_Verified_Update_Exit();
        });
        ob_start();
        try {
            do_action('wp_ajax_swf_check_github_updates');
        } catch (SWF_Verified_Update_Exit $exception) {
            // Capture WordPress's terminating response without ending the test process.
        }
        return json_decode(ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_check_ajax_rejects_invalid_nonce_before_http(): void
    {
        $this->assertSame(-1, $this->ajax('invalid'));
        $this->assertCount(0, $this->requests);
    }

    public function test_check_ajax_rejects_users_without_wordpress_update_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(array( 'role' => 'subscriber' )));
        $this->assertFalse($this->ajax(wp_create_nonce('swf_verified_updates'))['success']);
        $this->assertCount(0, $this->requests);
    }

    public function test_authorized_check_ajax_checks_but_never_installs(): void
    {
        // The shared AJAX endpoint has a site-admin screen even when called by network admin.
        set_current_screen('dashboard');
        $this->assertTrue($this->ajax(wp_create_nonce('swf_verified_updates'))['success']);
        $this->assertCount(3, $this->requests);
        $this->assertFalse($this->ajax(wp_create_nonce('swf_verified_updates'))['success']);
        $this->assertCount(3, $this->requests);
    }

    public function test_multisite_site_admin_context_cannot_check_without_network_ajax(): void
    {
        if (! is_multisite()) {
            $this->assertTrue(SWF_Update_Service::interactive());
            return;
        }
        set_current_screen('dashboard');
        $this->assertWPError(SWF_Update_Service::check(true));
        $this->assertCount(0, $this->requests);
        add_filter('wp_doing_ajax', '__return_true');
        revoke_super_admin($this->admin_id);
        $this->assertWPError(SWF_Update_Service::check(true));
        $this->assertCount(0, $this->requests);
    }

    public function test_deactivation_clears_only_own_offers_and_automatic_selections(): void
    {
        $this->assertIsArray(SWF_Update_Service::check());
        $file = array_key_first(SWF_Update_Provider::descriptors());
        update_site_option('auto_update_plugins', array( $file, 'other/other.php' ));
        set_site_transient('update_plugins', (object) array(
            'checked' => array( $file => '1.0.0', 'other/other.php' => '1.0.0' ),
            'response' => array( 'other/other.php' => (object) array( 'new_version' => '2.0.0' ) ),
        ));
        $this->assertArrayHasKey($file, get_site_transient('update_plugins')->response);
        SWF_Update_Provider::deactivate();
        $this->assertSame(array( 'other/other.php' ), get_site_option('auto_update_plugins'));
        $this->assertArrayNotHasKey($file, get_site_transient('update_plugins')->response);
        $this->assertArrayHasKey('other/other.php', get_site_transient('update_plugins')->response);
    }


    public function test_expired_lock_is_taken_over_and_new_owner_is_preserved(): void
    {
        $old = array( 'token' => 'expired', 'expires' => time() - 10 );
        add_site_option(SWF_Update_Store::LOCK, $old);
        $this->assertIsArray($new = SWF_Update_Store::acquire());
        SWF_Update_Store::release($old);
        $this->assertSame($new, get_site_option(SWF_Update_Store::LOCK));
    }

    public function test_signed_rollback_cannot_replace_a_previously_verified_release(): void
    {
        $this->assertIsArray(SWF_Update_Service::check());
        $state = SWF_Update_Store::read();
        $state['last_attempt'] = time() - 61;
        SWF_Update_Store::write($state);
        $this->manifest['packages'][0]['version'] = '8.0.0.0';
        $this->manifest['packages'][0]['filename'] = 'sw-forms-8.0.0.0.zip';
        $this->manifest['tag'] = 'v8.0.0.0';
        $this->release['tag_name'] = 'v8.0.0.0';
        $this->release['assets'][0]['name'] = 'sw-forms-8.0.0.0.zip';
        $this->sign();
        $this->assertWPError(SWF_Update_Service::check(true));
        $this->assertSame('9.0.0.0', SWF_Update_Store::read()['highest']['sw-forms']);
    }

    public function test_invalid_native_update_nonce_never_downloads_a_package(): void
    {
        $this->assertIsArray(SWF_Update_Service::check());
        $file = array_key_first(SWF_Update_Provider::descriptors());
        $_REQUEST = array( 'action' => 'upgrade-plugin', '_wpnonce' => 'forged' );
        $url = SWF_Update_Manifest::url($this->manifest, $this->manifest['packages'][0]);
        $this->assertWPError(apply_filters('upgrader_pre_download', false, $url, new stdClass(), array( 'plugin' => $file )));
        $this->assertCount(3, $this->requests);
    }
    public static function invalid_signed_metadata(): array
    {
        return array_map(static fn ($case) => [$case], [
            'repository', 'repository_id', 'schema', 'unknown_field', 'unknown_package_field',
            'release_id', 'asset_id', 'asset_name', 'asset_size', 'duplicate_asset_id',
            'duplicate_asset_name', 'missing_stable_flag', 'future', 'main_file', 'slug',
            'tag', 'requirements', 'version', 'hash',
        ]);
    }

    /** @dataProvider invalid_signed_metadata */
    public function test_signed_but_inconsistent_metadata_is_rejected(string $case): void
    {
        switch ($case) {
            case 'repository':
                $this->manifest['repository'] = 'foreign/plugin';
                break;
            case 'repository_id':
                $this->manifest['repository_id']++;
                break;
            case 'schema':
                $this->manifest['schema']++;
                break;
            case 'unknown_field':
                $this->manifest['unexpected'] = true;
                break;
            case 'unknown_package_field':
                $this->manifest['packages'][0]['unexpected'] = true;
                break;
            case 'release_id':
                $this->manifest['release_id']++;
                break;
            case 'asset_id':
                $this->manifest['packages'][0]['asset_id']++;
                break;
            case 'asset_name':
                $this->release['assets'][0]['name'] = 'different.zip';
                break;
            case 'asset_size':
                $this->release['assets'][0]['size']++;
                break;
            case 'duplicate_asset_id':
                $this->release['assets'][1]['id'] = 55;
                break;
            case 'duplicate_asset_name':
                $this->release['assets'][1]['name'] = $this->release['assets'][0]['name'];
                break;
            case 'missing_stable_flag':
                unset($this->release['draft']);
                break;
            case 'future':
                $this->manifest['issued_at'] = time() + 301;
                break;
            case 'main_file':
                $this->manifest['packages'][0]['main_file'] = 'other.php';
                break;
            case 'slug':
                $this->manifest['packages'][0]['slug'] = 'other';
                break;
            case 'tag':
                $this->manifest['tag'] = $this->release['tag_name'] = 'v9.0.0.0-beta';
                break;
            case 'requirements':
                $this->manifest['packages'][0]['requires_php'] = '';
                break;
            case 'version':
                $this->manifest['packages'][0]['version'] = 'latest';
                break;
            case 'hash':
                $this->manifest['packages'][0]['sha256'] = str_repeat('x', 64);
                break;
        }
        $this->sign();
        $this->assertWPError(SWF_Update_Manifest::verify($this->raw, $this->signature, $this->keys, $this->release));
    }

    public function test_duplicate_signature_envelope_keys_are_rejected(): void
    {
        $envelope = substr($this->signature, 0, -1) . ',"key_id":"test"}';
        $this->assertWPError(SWF_Update_Manifest::verify($this->raw, $envelope, $this->keys, $this->release));
    }

    public function test_plugin_copies_have_independent_hooks_keys_state_and_locks(): void
    {
        $directory = sys_get_temp_dir() . '/swf-peer-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            foreach (glob(SWF_DIR . 'includes/update/*.php') as $source) {
                $name = str_replace('sw-forms', 'sw-peer', basename($source));
                $code = str_replace(['class-sw-forms-', 'SWF_', 'swf_', 'swf-', 'swfVerifiedUpdates'], ['class-sw-peer-', 'SWFPeer_', 'swfpeer_', 'swfpeer-', 'swfPeerVerifiedUpdates'], file_get_contents($source));
                file_put_contents($directory . '/' . $name, $code);
            }
            file_put_contents($directory . '/descriptors.php', "<?php return ['sw-peer/sw-peer.php' => ['slug' => 'sw-peer', 'version' => '1.0.0']];");
            // Activate two independent plugin fixtures through WordPress in its private core.
            $this->assertStringStartsWith(SWF_DIR . 'tests/wp/vendor/', realpath(WP_PLUGIN_DIR));
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            foreach (['sw-forms', 'sw-peer'] as $slug) {
                $this->assertDirectoryDoesNotExist(WP_PLUGIN_DIR . '/' . $slug);
                mkdir(WP_PLUGIN_DIR . '/' . $slug, 0700);
            }
            file_put_contents(WP_PLUGIN_DIR . '/sw-forms/sw-forms.php', "<?php\n/*\nPlugin Name: SW Forms isolated activation fixture\n*/\nrequire_once " . var_export(SWF_DIR . 'includes/update/class-sw-forms-update-provider.php', true) . ";\n");
            file_put_contents(WP_PLUGIN_DIR . '/sw-peer/sw-peer.php', "<?php\n/*\nPlugin Name: SW Peer isolated activation fixture\n*/\nrequire_once " . var_export($directory . '/class-sw-peer-update-provider.php', true) . ";\n");
            $this->assertNull(activate_plugin('sw-forms/sw-forms.php', '', is_multisite()));
            $this->assertNull(activate_plugin('sw-peer/sw-peer.php', '', is_multisite()));
            $this->assertTrue(is_plugin_active('sw-forms/sw-forms.php'));
            $this->assertTrue(is_plugin_active('sw-peer/sw-peer.php'));
            require $directory . '/class-sw-peer-update-service.php';
            $peerKeys = ['peer' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))];
            add_filter('swfpeer_verified_release_public_keys', static fn () => $peerKeys);
            $this->assertSame($peerKeys, SWFPeer_Update_Service::keys());
            $this->assertSame($this->keys, SWF_Update_Service::keys());
            $this->assertTrue(has_action('wp_ajax_swf_check_github_updates') !== false);
            $this->assertTrue(has_action('wp_ajax_swfpeer_check_github_updates') !== false);
            SWFPeer_Update_Store::write(['last_attempt' => 123]);
            $this->assertSame([], SWF_Update_Store::read());
            $mutexNames = [];
            $captureMutex = static function ($query) use (&$mutexNames) {
                if (preg_match("/SELECT GET_LOCK\('([^']+)'/", $query, $match)) {
                    $mutexNames[] = $match[1];
                }
                return $query;
            };
            add_filter('query', $captureMutex);
            try {
                $ownLock = SWF_Update_Store::acquire();
                $peerLock = SWFPeer_Update_Store::acquire();
            } finally {
                remove_filter('query', $captureMutex);
            }
            if (is_multisite()) {
                $this->assertCount(2, $mutexNames);
                $this->assertNotSame($mutexNames[0], $mutexNames[1]);
                foreach ($mutexNames as $mutexName) {
                    $this->assertLessThanOrEqual(64, strlen($mutexName));
                }
            }
            $this->assertIsArray($ownLock);
            $this->assertIsArray($peerLock);
            SWFPeer_Update_Store::release($peerLock);
            $this->assertTrue(SWF_Update_Store::owns($ownLock));
            SWF_Update_Store::release($ownLock);
            $file = array_key_first(SWF_Update_Provider::descriptors());
            $own = (object) ['plugin' => $file];
            $peer = (object) ['plugin' => 'sw-peer/sw-peer.php'];
            $foreign = (object) ['plugin' => 'foreign/plugin.php'];
            $this->assertFalse(apply_filters('auto_update_plugin', true, $own));
            $this->assertFalse(apply_filters('auto_update_plugin', true, $peer));
            $this->assertTrue(apply_filters('auto_update_plugin', true, $foreign));
            $this->assertSame('foreign', apply_filters('upgrader_pre_download', 'foreign', 'https://foreign.invalid/package.zip', new stdClass(), ['plugin' => 'foreign/plugin.php']));
            remove_all_filters('swf_verified_release_public_keys');
            if (!defined('SW_PLUGIN_RELEASE_PUBLIC_KEYS')) {
                define('SW_PLUGIN_RELEASE_PUBLIC_KEYS', $peerKeys);
            }
            $this->assertSame(require SWF_DIR . 'includes/update/trusted-keys.php', SWF_Update_Service::keys());
        } finally {
            deactivate_plugins(['sw-forms/sw-forms.php', 'sw-peer/sw-peer.php'], true);
            foreach (['sw-forms', 'sw-peer'] as $slug) {
                wp_delete_file(WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php');
                if (is_dir(WP_PLUGIN_DIR . '/' . $slug)) {
                    rmdir(WP_PLUGIN_DIR . '/' . $slug);
                }
            }
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
    public function test_native_plugin_row_contains_authorized_check_and_escaped_local_status_only(): void
    {
        $file = array_key_first(SWF_Update_Provider::descriptors());
        $meta = SWF_Update_Provider::row_meta(['foreign link'], $file);
        $this->assertCount(2, $meta);
        $this->assertStringContainsString('id="swf-verified-updates-check"', $meta[1]);
        $this->assertStringContainsString('No verified update check yet.', $meta[1]);
        $this->assertStringContainsString('id="swf-verified-updates-reload"', $meta[1]);
        $this->assertStringContainsString(esc_url(is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php')), $meta[1]);
        $this->assertSame(['foreign link'], SWF_Update_Provider::row_meta(['foreign link'], 'other/other.php'));
        $this->assertIsArray(SWF_Update_Service::check());
        $state = SWF_Update_Store::read();
        $state['error'] = '<b>unsafe</b>';
        SWF_Update_Store::write($state);
        $html = SWF_Update_Provider::row_meta([], $file)[0];
        $this->assertStringContainsString('Last verified check:', $html);
        $this->assertStringNotContainsString('<b>unsafe</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;unsafe&lt;/b&gt;', $html);
        $this->assertCount(3, $this->requests);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(['foreign link'], SWF_Update_Provider::row_meta(['foreign link'], $file));
    }
    public function test_ajax_and_verification_messages_use_the_shipped_translations(): void
    {
        $english = SWF_Update_Service::check();
        $this->assertSame('Signed release information verified. Install updates manually through the Plugins page.', $english['message']);
        $this->assertTrue(load_textdomain('swf-update-test-de', SWF_DIR . 'languages/sw-forms-de_DE.mo'));
        $translate = static fn ($translation, $text, $domain) => $domain === 'sw-forms' ? __($text, 'swf-update-test-de') : $translation;
        add_filter('gettext', $translate, 10, 3);
        try {
            delete_site_option(SWF_Update_Store::STATE);
            $german = SWF_Update_Service::check();
            $this->assertSame('Signierte Release-Informationen geprüft. Installation ausschließlich über die Plugin-Verwaltung.', $german['message']);
            $error = SWF_Update_Manifest::verify($this->raw . ' ', $this->signature, $this->keys, $this->release);
            $this->assertWPError($error);
            $this->assertSame('Release-Verifikation fehlgeschlagen: Unbekannter Schlüssel oder ungültige Release-Signatur.', $error->get_error_message());
            $this->keys = [];
            $error = SWF_Update_Service::check(true);
            $this->assertWPError($error);
            $this->assertSame('Öffentlicher Release-Schlüssel oder Sodium fehlt. GitHub-Updates sind gesperrt.', $error->get_error_message());
        } finally {
            remove_filter('gettext', $translate, 10);
            unload_textdomain('swf-update-test-de');
        }
    }
}
