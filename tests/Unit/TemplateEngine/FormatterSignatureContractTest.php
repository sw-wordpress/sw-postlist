<?php
/**
 * The built-in formatter signatures are identical in PHP and TypeScript: both sides compare against one JSON file.
 * Set SWP_UPDATE_CONTRACT=1 to rewrite the file after an intentional change.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit\TemplateEngine;

use PHPUnit\Framework\TestCase;
use SW\Postlist\TemplateEngine\Formatter\BuiltinFormatters;

final class FormatterSignatureContractTest extends TestCase {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function signatures(): array {
		$signatures = array();
		foreach ( BuiltinFormatters::all() as $formatter ) {
			$signatures[ $formatter->name() ] = $formatter->signature()->to_array();
		}
		ksort( $signatures );
		return $signatures;
	}

	public function test_signatures_match_the_shared_contract_file(): void {
		$file    = dirname( __DIR__, 2 ) . '/contract/formatter-signatures.json';
		$current = json_encode( $this->signatures(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free unit test.
		if ( '1' === getenv( 'SWP_UPDATE_CONTRACT' ) ) {
			file_put_contents( $file, $current ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture generation.
		}
		$this->assertSame( $current, file_get_contents( $file ), 'Formatter signatures changed: update tests/contract/formatter-signatures.json (SWP_UPDATE_CONTRACT=1) and the TypeScript specs.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
	}
}
