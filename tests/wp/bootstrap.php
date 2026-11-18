<?php
/**
 * Bootstrap for WordPress integration tests.
 *
 * @package sw-postlist
 */

// SWP_WP_VENDOR selects the dependency set: "vendor" (current WordPress) or "vendor-65" (minimum version, composer test:wp65).
$swp_vendor    = getenv( 'SWP_WP_VENDOR' ) ?: 'vendor';
$swp_tests_dir = __DIR__ . '/' . $swp_vendor . '/wp-phpunit/wp-phpunit';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'SW_POSTLIST_AUTOLOADER', __DIR__ . '/' . $swp_vendor . '/autoload.php' );

require_once $swp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/sw-postlist.php';
	}
);

require $swp_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/tests/SWP_TestCase.php';
