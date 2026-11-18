<?php
/**
 * Minimal WordPress function doubles for loading the plugin entry files without WordPress.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

define( 'ABSPATH', '/test/wordpress/' );

$GLOBALS['sw_postlist_test_actions']    = array();
$GLOBALS['sw_postlist_test_activation'] = null;

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/sw-postlist/';
}

function plugin_basename( string $file ): string {
	return 'sw-postlist/' . basename( $file );
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['sw_postlist_test_actions'][ $hook ][] = $callback;
}

function register_activation_hook( string $file, mixed $callback ): void {
	$GLOBALS['sw_postlist_test_activation'] = $callback;
}

function register_deactivation_hook( string $file, mixed $callback ): void {
	$GLOBALS['sw_postlist_test_deactivation'] = $callback;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_html( string $text ): string {
	return $text;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function is_admin(): bool {
	return false;
}

function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['sw_postlist_test_filters'][ $hook ][] = $callback;
}
