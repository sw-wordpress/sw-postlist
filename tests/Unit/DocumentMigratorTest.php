<?php
/**
 * Migration framework: ordered steps, unknown and newer versions.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Document\DocumentMigrator;

final class DocumentMigratorTest extends TestCase {

	public function test_current_documents_pass_unchanged(): void {
		$result = ( new DocumentMigrator( 1 ) )->migrate(
			array(
				'schema_version' => 1,
				'title'          => 'T',
			)
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame(
			array(
				'schema_version' => 1,
				'title'          => 'T',
			),
			$result->value()
		);
	}

	public function test_steps_run_in_order_and_stamp_the_version(): void {
		$migrator = new DocumentMigrator(
			3,
			array(
				1 => static function ( array $document ): array {
					$document['title'] .= '+v2';
					return $document;
				},
				2 => static function ( array $document ): array {
					$document['title'] .= '+v3';
					return $document;
				},
			)
		);

		$result = $migrator->migrate(
			array(
				'schema_version' => 1,
				'title'          => 'T',
			)
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame(
			array(
				'schema_version' => 3,
				'title'          => 'T+v2+v3',
			),
			$result->value()
		);
		$this->assertSame(
			'T+v3',
			( $migrator->migrate(
				array(
					'schema_version' => 2,
					'title'          => 'T',
				)
			) )->value()['title']
		);
	}

	public function test_missing_step_newer_and_invalid_versions_are_rejected(): void {
		$migrator = new DocumentMigrator( 2 );

		$this->assertSame( array( 'schema_version_unsupported' ), $migrator->migrate( array( 'schema_version' => 1 ) )->error_codes() );
		$this->assertSame( array( 'schema_version_newer' ), $migrator->migrate( array( 'schema_version' => 3 ) )->error_codes() );
		foreach ( array( array(), array( 'schema_version' => '1' ), array( 'schema_version' => 0 ), array( 'schema_version' => 1.5 ) ) as $document ) {
			$this->assertSame( array( 'schema_version_missing' ), $migrator->migrate( $document )->error_codes() );
		}
	}

	public function test_results_expose_value_only_when_valid(): void {
		$invalid = ( new DocumentMigrator( 1 ) )->migrate( array() );

		$this->assertFalse( $invalid->is_valid() );
		$this->assertSame( 'schema_version', $invalid->errors()[0]->to_array()['path'] );
		$this->expectException( \LogicException::class );
		$invalid->value();
	}
}
