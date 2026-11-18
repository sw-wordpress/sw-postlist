<?php
/**
 * Deterministic fuzzing of the view document validator: hostile input never throws, valid output round-trips.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Document\ViewDocumentValidator;

final class ViewDocumentFuzzTest extends TestCase {

	private int $seed = 20261118;

	private function next( int $max ): int {
		$this->seed = ( $this->seed * 1103515245 + 12345 ) & 0x7fffffff;
		return $this->seed % $max;
	}

	private function junk(): mixed {
		$values = array( null, true, false, 0, -1, 1.5, PHP_INT_MAX, '', ' ', 'any', 'post:any', '<script>', "a\0b", str_repeat( 'x', 600 ), array(), array( 1, 2 ), array( 'a' => array( 'b' => 1 ) ), array( array() ), '2026-13-45', 'asc', 'rand' );
		return $values[ $this->next( count( $values ) ) ];
	}

	/**
	 * @param array<string, mixed> $node Document or sub tree.
	 * @return array<string, mixed>
	 */
	private function mutate( array $node ): array {
		foreach ( array_keys( $node ) as $key ) {
			$roll = $this->next( 40 );
			if ( 0 === $roll ) {
				$node[ $key ] = $this->junk();
			} elseif ( 1 === $roll ) {
				unset( $node[ $key ] );
			} elseif ( is_array( $node[ $key ] ) && ! array_is_list( $node[ $key ] ) ) {
				$node[ $key ] = $this->mutate( $node[ $key ] );
			}
		}
		if ( 0 === $this->next( 20 ) ) {
			$node[ 'extra' . $this->next( 5 ) ] = $this->junk();
		}
		return $node;
	}

	public function test_mutated_documents_never_throw_and_valid_ones_round_trip(): void {
		$base      = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/contract/view-document/valid-full.json' ), true, 512, JSON_THROW_ON_ERROR )['input']; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
		$validator = new ViewDocumentValidator();
		$valid     = 0;
		$invalid   = 0;

		for ( $round = 0; $round < 1500; $round++ ) {
			$result = $validator->validate( $this->mutate( $base ) );
			if ( $result->is_valid() ) {
				++$valid;
				$again = $validator->validate( $result->value()->to_array() );
				$this->assertTrue( $again->is_valid(), 'A normalized document must stay valid.' );
				$this->assertSame( $result->value()->to_array(), $again->value()->to_array() );
				$this->assertIsString( $result->value()->source_id );
				$this->assertNotContains( $result->value()->source_id, array( 'any', 'post:any' ) );
			} else {
				++$invalid;
				$this->assertNotEmpty( $result->errors() );
			}
		}
		$this->assertGreaterThan( 50, $valid, 'The fuzzer must also produce valid documents.' );
		$this->assertGreaterThan( 50, $invalid );
	}
}
