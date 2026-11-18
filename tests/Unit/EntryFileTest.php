<?php
/**
 * Loading the plugin entry files outside WordPress.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class EntryFileTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_entry_file_registers_lifecycle_and_boots(): void {
		require_once __DIR__ . '/../Support/wp-function-stubs.php';
		require dirname( __DIR__, 2 ) . '/sw-postlist.php';

		$this->assertTrue( defined( 'SW_POSTLIST_VERSION' ) );
		$this->assertSame( array( \SW\Postlist\Lifecycle::class, 'activate' ), $GLOBALS['sw_postlist_test_activation'] );
		$this->assertSame( array( \SW\Postlist\Lifecycle::class, 'deactivate' ), $GLOBALS['sw_postlist_test_deactivation'] );
		$this->assertArrayHasKey( 'init', $GLOBALS['sw_postlist_test_actions'] );
		$this->assertArrayNotHasKey( 'admin_notices', $GLOBALS['sw_postlist_test_actions'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_missing_autoloader_reports_notice_and_stops(): void {
		require_once __DIR__ . '/../Support/wp-function-stubs.php';
		define( 'SW_POSTLIST_AUTOLOADER', '/nonexistent/autoload.php' );
		require dirname( __DIR__, 2 ) . '/sw-postlist.php';

		$this->assertArrayHasKey( 'admin_notices', $GLOBALS['sw_postlist_test_actions'] );
		$this->assertNull( $GLOBALS['sw_postlist_test_activation'] );
		ob_start();
		( $GLOBALS['sw_postlist_test_actions']['admin_notices'][0] )();
		$this->assertStringContainsString( 'vendor directory is missing', (string) ob_get_clean() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unsupported_php_version_reports_notice_and_stops(): void {
		require_once __DIR__ . '/../Support/wp-function-stubs.php';
		define( 'SW_POSTLIST_MIN_PHP', '99.0' );
		require dirname( __DIR__, 2 ) . '/sw-postlist.php';

		$this->assertNull( $GLOBALS['sw_postlist_test_activation'] );
		ob_start();
		( $GLOBALS['sw_postlist_test_actions']['admin_notices'][0] )();
		$this->assertStringContainsString( 'requires PHP 99.0', (string) ob_get_clean() );
	}
}
