<?php
/**
 * WordPress test suite configuration. Uses ONLY the private throw-away MariaDB instance (bin/test-db.sh),
 * never the development site's database.
 */

define( 'ABSPATH', __DIR__ . '/' . ( getenv( 'SWP_WP_VENDOR' ) ?: 'vendor' ) . '/johnpbloch/wordpress-core/' );
if ( getenv( 'WP_TESTS_MULTISITE' ) === '1' ) {
	define( 'WP_TESTS_MULTISITE', true );
}

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'SWP_WP_TEST_DB_NAME' ) ?: 'swp_wp_tests' );
define( 'DB_USER', getenv( 'SWP_TEST_DB_USER' ) ?: 'swp' );
define( 'DB_PASSWORD', getenv( 'SWP_TEST_DB_PASSWORD' ) ?: 'swp' );
define( 'DB_HOST', ( getenv( 'SWP_TEST_DB_HOST' ) ?: '127.0.0.1' ) . ':' . ( getenv( 'SWP_TEST_DB_PORT' ) ?: '33079' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'sw-postlist Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

$table_prefix = 'wptests_';
