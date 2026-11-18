<?php
// Disposable E2E instance: install WordPress and activate the plugin.
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = '127.0.0.1:8090';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'SWP_E2E_WP' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) {
	wp_install( 'SWP E2E', 'admin', 'admin@example.org', true, '', 'e2e-admin-pass' );
}
update_option( 'siteurl', 'http://127.0.0.1:8090' );
update_option( 'home', 'http://127.0.0.1:8090' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'timezone_string', 'Europe/Berlin' );
update_option( 'WPLANG', '' );
if ( 'classic' === getenv( 'SWP_E2E_THEME' ) ) {
	switch_theme( 'swp-classic' );
}
$result = activate_plugin( 'sw-postlist/sw-postlist.php' );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() );
	exit( 1 );
}
update_user_meta( 1, 'locale', 'de_DE' );
echo "installed\n";
