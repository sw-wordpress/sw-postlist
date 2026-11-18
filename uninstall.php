<?php
/**
 * Uninstall handler: removes only SW Postlist data.
 *
 * @package sw-postlist
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/sw-postlist.php';

if ( class_exists( \SW\Postlist\Lifecycle::class ) ) {
	\SW\Postlist\Lifecycle::uninstall();
}
