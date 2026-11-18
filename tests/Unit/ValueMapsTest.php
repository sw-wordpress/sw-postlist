<?php
/**
 * Named maps: hide, replace and strip a prefix; malformed maps never survive.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Map\ValueMaps;

final class ValueMapsTest extends TestCase {

	private function maps(): ValueMaps {
		return new ValueMaps(
			ValueMaps::sanitize(
				array(
					'categories' => array(
						'hide'         => array( 'Uncategorized', 'no-name' ),
						'labels'       => array(
							'Kategorie Marktplatz' => 'Markt',
							'jugend'               => 'Kinder & Jugend',
						),
						'strip_prefix' => 'Kategorie ',
					),
					'plain'      => array(),
				)
			)
		);
	}

	public function test_hidden_values_match_label_or_key_ignoring_case(): void {
		$this->assertNull( $this->maps()->apply( 'categories', 'Uncategorized', 'uncategorized' ) );
		$this->assertNull( $this->maps()->apply( 'categories', 'UNCATEGORIZED' ) );
		$this->assertNull( $this->maps()->apply( 'categories', 'Anything', 'no-name' ) );
		$this->assertSame( 'News', $this->maps()->apply( 'categories', 'News', 'news' ) );
	}

	public function test_labels_by_label_or_key_win_over_the_prefix_rule(): void {
		$this->assertSame( 'Markt', $this->maps()->apply( 'categories', 'Kategorie Marktplatz', 'marktplatz' ) );
		$this->assertSame( 'Kinder & Jugend', $this->maps()->apply( 'categories', 'Jugend', 'jugend' ) );
	}

	public function test_prefix_is_removed_only_in_front_and_never_leaves_an_empty_label(): void {
		$this->assertSame( 'Feldstraße', $this->maps()->apply( 'categories', 'Kategorie Feldstraße' ) );
		$this->assertSame( 'Die Kategorie Feldstraße', $this->maps()->apply( 'categories', 'Die Kategorie Feldstraße' ) );
		$this->assertSame( 'Kategorie ', $this->maps()->apply( 'categories', 'Kategorie ' ) );
	}

	public function test_unknown_and_empty_maps_leave_values_alone(): void {
		$this->assertSame( 'Uncategorized', $this->maps()->apply( 'missing', 'Uncategorized' ) );
		$this->assertSame( 'Uncategorized', $this->maps()->apply( 'plain', 'Uncategorized' ) );
		$this->assertSame( array( 'categories', 'plain' ), $this->maps()->names() );
		$this->assertTrue( $this->maps()->has( 'plain' ) );
		$this->assertFalse( $this->maps()->has( 'missing' ) );
	}

	public function test_sanitizing_drops_malformed_maps_and_bounds_everything(): void {
		$clean = ValueMaps::sanitize(
			array(
				'Bad Name' => array( 'hide' => array( 'x' ) ),
				'ok'       => array(
					'hide'         => array( 'a', 'A', '  a  ', array( 'nested' ), null, str_repeat( 'x', 300 ), "ctl\0char" ),
					'labels'       => array(
						'from'     => 'to',
						''         => 'empty key',
						'to-empty' => '',
						'arr'      => array( 'x' ),
					),
					'strip_prefix' => "Pre\x00fix " . str_repeat( 'y', 80 ),
				),
				'blank'    => array( 'strip_prefix' => '   ' ),
				'junk'     => 'string',
				5          => array( 'hide' => array( 'x' ) ),
			)
		);
		$this->assertSame( array( 'ok', 'blank' ), array_keys( $clean ) );
		$this->assertCount( 3, $clean['ok']['hide'], 'Duplicates by case collapse; invalid values are dropped.' );
		$this->assertSame( array( 'from' => 'to' ), $clean['ok']['labels'] );
		$this->assertLessThanOrEqual( 50, mb_strlen( $clean['ok']['strip_prefix'] ) );
		$this->assertStringNotContainsString( "\0", $clean['ok']['strip_prefix'] );
		$this->assertSame( '', $clean['blank']['strip_prefix'] );
		$this->assertSame( array(), ValueMaps::sanitize( 'x' ) );

		$many = array_map( static fn ( int $i ): string => 'term' . $i, range( 1, 300 ) );
		$this->assertCount( ValueMaps::MAX_ENTRIES, ValueMaps::sanitize( array( 'm' => array( 'hide' => $many ) ) )['m']['hide'] );
		$maps = array();
		foreach ( range( 1, 50 ) as $i ) {
			$maps[ 'map' . $i ] = array( 'hide' => array( 'x' ) );
		}
		$this->assertCount( ValueMaps::MAX_MAPS, ValueMaps::sanitize( $maps ) );
	}
}
