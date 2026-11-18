<?php
/**
 * Shape of the shared PHP/TypeScript contract fixtures.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContractFixturesTest extends TestCase {

	public function test_every_fixture_is_well_formed(): void {
		$files = glob( dirname( __DIR__ ) . '/contract/*/*.json' );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$fixture = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
			$this->assertIsArray( $fixture, $file );
			foreach ( array( 'name', 'type', 'input', 'expect' ) as $key ) {
				$this->assertArrayHasKey( $key, $fixture, $file . ' lacks ' . $key );
			}
			$this->assertSame( basename( dirname( $file ) ), $fixture['type'], $file );
		}
	}
}
