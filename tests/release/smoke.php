<?php
/**
 * Smoke-test a checkout or staged release without WordPress, network or database access.
 * Usage: php tests/release/smoke.php [plugin-directory]
 *
 * @package sw-postlist
 */

declare(strict_types=1);

require __DIR__ . '/../Support/wp-function-stubs.php';

( static function ( string $root ): void {
	require $root . '/sw-postlist.php';

	foreach ( array( 'sw_postlist_test_activation', 'sw_postlist_test_deactivation' ) as $key ) {
		if ( ! is_callable( $GLOBALS[ $key ] ?? null ) ) {
			throw new RuntimeException( 'Lifecycle callback could not be loaded: ' . $key );
		}
	}
	if ( ! isset( $GLOBALS['sw_postlist_test_actions']['init'] ) ) {
		throw new RuntimeException( 'Plugin did not register its init hook.' );
	}
	if ( isset( $GLOBALS['sw_postlist_test_actions']['admin_notices'] ) ) {
		throw new RuntimeException( 'Plugin reported a bootstrap error.' );
	}
	foreach ( array( \SW\Postlist\Plugin::class, \SW\Postlist\Services::class, \SW\Postlist\Lifecycle::class ) as $class_name ) {
		if ( ! class_exists( $class_name ) ) {
			throw new RuntimeException( 'Plugin class could not be loaded: ' . $class_name );
		}
	}
	echo 'PASS: plugin bootstrap and PSR-4 mapping at ' . $root . PHP_EOL;
} )( $argv[1] ?? dirname( __DIR__, 2 ) );
