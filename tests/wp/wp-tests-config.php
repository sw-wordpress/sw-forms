<?php

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress PHPUnit conventions.

/**
 * WordPress test suite configuration. Uses ONLY the private throw-away MariaDB instance
 * (bin/test-db.sh), never the development site's database.
 */

define('ABSPATH', __DIR__ . '/vendor/johnpbloch/wordpress-core/');
define('WP_DEFAULT_THEME', 'default');
define('WP_DEBUG', true);

define('DB_NAME', getenv('SWF_WP_TEST_DB_NAME') ?: 'swf_wp_tests');
define('DB_USER', getenv('SWF_TEST_DB_USER') ?: 'swf');
define('DB_PASSWORD', getenv('SWF_TEST_DB_PASSWORD') ?: 'swf');
define('DB_HOST', ( getenv('SWF_TEST_DB_HOST') ?: '127.0.0.1' ) . ':' . ( getenv('SWF_TEST_DB_PORT') ?: '33110' ));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'SW Forms Tests');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');

// Salts. wp-phpunit does not define them, but the plugin encrypts secrets with them.
foreach (array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $swf_test_salt) {
    if (! defined($swf_test_salt)) {
        define($swf_test_salt, 'swf-test-' . strtolower($swf_test_salt));
    }
}

$table_prefix = 'wptests_';
