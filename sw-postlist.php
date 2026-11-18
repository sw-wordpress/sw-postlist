<?php
/**
 * Plugin Name:       SW Postlist
 * Description:       Configurable views of posts, pages and custom post types with templates, placeholders and formatters.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Stefan Warnat
 * Author URI:        https://stefanwarnat.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sw-postlist
 * Domain Path:       /languages
 *
 * @package sw-postlist
 */

defined( 'ABSPATH' ) || exit;

define( 'SW_POSTLIST_VERSION', '0.1.0' );
define( 'SW_POSTLIST_FILE', __FILE__ );
define( 'SW_POSTLIST_DIR', plugin_dir_path( __FILE__ ) );
define( 'SW_POSTLIST_URL', plugin_dir_url( __FILE__ ) );
// The minimum may be pre-defined by the test environment to exercise the incompatibility notice.
defined( 'SW_POSTLIST_MIN_PHP' ) || define( 'SW_POSTLIST_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, SW_POSTLIST_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'SW Postlist requires PHP %1$s or newer. You are running PHP %2$s.', 'sw-postlist' ),
						SW_POSTLIST_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

// SW_POSTLIST_AUTOLOADER may be defined by the test environment to supply its own dependency set.
$sw_postlist_autoload = defined( 'SW_POSTLIST_AUTOLOADER' ) ? SW_POSTLIST_AUTOLOADER : SW_POSTLIST_DIR . 'vendor-prefixed/autoload.php';
if ( ! defined( 'SW_POSTLIST_AUTOLOADER' ) && ! is_readable( $sw_postlist_autoload ) ) {
	// Source checkouts use Composer; release packages only ship isolated dependencies.
	$sw_postlist_autoload = SW_POSTLIST_DIR . 'vendor/autoload.php';
}
if ( ! is_readable( $sw_postlist_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'SW Postlist: the vendor directory is missing. Install the release package or run "composer install".', 'sw-postlist' )
			);
		}
	);
	return;
}
$sw_postlist_loader = require $sw_postlist_autoload;
// Mozart's loader only knows the dependencies, not the plugin's own classes.
$sw_postlist_loader->addPsr4( 'SW\\Postlist\\', SW_POSTLIST_DIR . 'src/' );
unset( $sw_postlist_autoload, $sw_postlist_loader );

register_activation_hook( __FILE__, array( \SW\Postlist\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SW\Postlist\Lifecycle::class, 'deactivate' ) );

\SW\Postlist\Plugin::instance()->boot();
