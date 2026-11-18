<?php
/**
 * Registry validation: faulty registrations become diagnostics, never fatal errors.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Tests\Support\FakeSource;

final class SourceRegistryTest extends TestCase {

	public function test_valid_sources_are_registered_by_id(): void {
		$source   = new FakeSource( 'post:event' );
		$registry = new SourceRegistry( array( $source, new FakeSource( 'post:page' ) ) );

		$this->assertSame( $source, $registry->get( 'post:event' ) );
		$this->assertSame( array( 'post:event', 'post:page' ), array_keys( $registry->all() ) );
		$this->assertSame( array(), $registry->diagnostics() );
		$this->assertNull( $registry->get( 'post:unknown' ) );
	}

	public function test_invalid_registrations_are_reported_and_skipped(): void {
		$registry = new SourceRegistry(
			array(
				'not a source',
				new \stdClass(),
				new FakeSource( 'Bad ID!' ),
				new FakeSource( 'post:any' ),
				new FakeSource( 'any' ),
				new FakeSource( 'post:dup' ),
				new FakeSource( 'post:dup' ),
				new FakeSource(
					'post:nolabel',
					array(),
					array(),
					array(
						'taxonomies'      => array(),
						'orderby'         => array(),
						'visitor_filters' => array(),
						'count_mode'      => 'exact',
					),
					0,
					false,
					'  '
				),
				new FakeSource(
					'post:nomode',
					array(),
					array(),
					array(
						'taxonomies'      => array(),
						'orderby'         => array(),
						'visitor_filters' => array(),
					)
				),
				new FakeSource(
					'post:badlist',
					array(),
					array(),
					array(
						'taxonomies'      => array( 1 ),
						'orderby'         => array(),
						'visitor_filters' => array(),
						'count_mode'      => 'exact',
					)
				),
				new FakeSource( 'post:ok' ),
			)
		);

		$this->assertSame( array( 'post:dup', 'post:ok' ), array_keys( $registry->all() ) );
		$codes = array_column( $registry->diagnostics(), 'code' );
		$this->assertSame(
			array( 'source_interface', 'source_interface', 'source_id_invalid', 'source_id_invalid', 'source_id_invalid', 'source_id_duplicate', 'source_label_empty', 'source_schema_invalid', 'source_schema_invalid' ),
			$codes
		);
	}

	public function test_non_array_filter_result_is_a_diagnostic(): void {
		$registry = new SourceRegistry( 'oops' );

		$this->assertSame( array(), $registry->all() );
		$this->assertSame( 'sources_not_array', $registry->diagnostics()[0]['code'] );
	}

	public function test_a_throwing_source_does_not_break_registration(): void {
		$throwing = new class() implements \SW\Postlist\Application\Port\EntrySourceInterface {
			public function id(): string {
				throw new \RuntimeException( 'boom' );
			}
			public function label(): string {
				return 'x';
			}
			public function query_fields(): array {
				return array();
			}
			public function query( \SW\Postlist\Domain\Source\QueryArgs $args ): \SW\Postlist\Domain\Source\EntryResult {
				return \SW\Postlist\Domain\Source\EntryResult::empty();
			}
			public function placeholders(): array {
				return array();
			}
			public function can_view( \SW\Postlist\Domain\Source\Entry $entry, ?int $user_id ): bool {
				return false;
			}
		};
		$registry = new SourceRegistry( array( $throwing, new FakeSource( 'post:ok' ) ) );

		$this->assertSame( array( 'post:ok' ), array_keys( $registry->all() ) );
		$this->assertSame( 'source_failed', $registry->diagnostics()[0]['code'] );
	}
}
