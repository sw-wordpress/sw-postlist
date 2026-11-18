<?php
/**
 * View and template documents against the shared contract fixtures.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Document\TemplateDocumentValidator;
use SW\Postlist\Application\Document\ViewDocumentValidator;

final class DocumentValidationContractTest extends TestCase {

	/**
	 * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
	 */
	public static function fixtures(): iterable {
		foreach ( array( 'view-document', 'template-document' ) as $type ) {
			foreach ( glob( dirname( __DIR__ ) . '/contract/' . $type . '/*.json' ) ?: array() as $file ) {
				$fixture = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
				yield $fixture['name'] => array( $type, $fixture['input'], $fixture['expect'] );
			}
		}
	}

	/**
	 * @param array<string, mixed> $input  Raw document.
	 * @param array<string, mixed> $expect Expected outcome.
	 */
	#[DataProvider( 'fixtures' )]
	public function test_fixture( string $type, array $input, array $expect ): void {
		$result = 'view-document' === $type
			? ( new ViewDocumentValidator() )->validate( $input )
			: ( new TemplateDocumentValidator() )->validate( $input );

		if ( isset( $expect['valid'] ) ) {
			$this->assertTrue( $result->is_valid(), self::describe_errors( $result->errors() ) );
			return;
		}
		$this->assertFalse( $result->is_valid() );
		$this->assertSame( $expect['errors'], array_values( array_unique( $result->error_codes() ) ) );
	}

	/**
	 * @param array<int, \SW\Postlist\Domain\Validation\ValidationError> $errors Findings.
	 */
	private static function describe_errors( array $errors ): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free unit test.
			array_map( static fn ( $error ): array => $error->to_array(), $errors )
		);
	}
}
