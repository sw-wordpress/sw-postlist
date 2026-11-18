<?php
/**
 * The template engine against the shared contract fixtures (PHP and TypeScript run the same cases).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Postlist\TemplateEngine\CompileContext;
use SW\Postlist\TemplateEngine\Compiler;
use SW\Postlist\TemplateEngine\EngineProfile;
use SW\Postlist\TemplateEngine\Formatter\BuiltinFormatters;
use SW\Postlist\TemplateEngine\Formatter\FormatterRegistry;
use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\Renderer;
use SW\Postlist\Tests\Support\Engine\ArrayResolver;
use SW\Postlist\Tests\Support\Engine\ArraySchema;
use SW\Postlist\Tests\Support\Engine\BasicEscaper;
use SW\Postlist\Tests\Support\Engine\BasicLocale;
use SW\Postlist\Tests\Support\Engine\FixedClock;

final class TemplateEngineContractTest extends TestCase {

	/**
	 * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
	 */
	public static function fixtures(): iterable {
		foreach ( glob( dirname( __DIR__ ) . '/contract/template-engine/*.json' ) ?: array() as $file ) {
			$fixture = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
			yield $fixture['name'] => array( $fixture['input'], $fixture['expect'] );
		}
	}

	/**
	 * @param array<string, mixed> $input  Template, CSS, role, profile and data.
	 * @param array<string, mixed> $expect Expected html/css or error codes.
	 */
	#[DataProvider( 'fixtures' )]
	public function test_fixture( array $input, array $expect ): void {
		$profile    = 'cloudcalendar' === $input['profile'] ? EngineProfile::cloudcalendar() : EngineProfile::postlist();
		$formatters = new FormatterRegistry( BuiltinFormatters::all() );
		$context    = new CompileContext( $profile, ArraySchema::contract(), $formatters, (string) $input['role'] );

		$result = ( new Compiler() )->compile( (string) $input['template'], (string) $input['css'], $context, 7 );

		if ( isset( $expect['errors'] ) ) {
			$this->assertFalse( $result->is_valid(), 'Expected errors ' . implode( ',', $expect['errors'] ) );
			$codes = array_values( array_unique( $result->error_codes() ) );
			sort( $codes );
			$this->assertSame( $expect['errors'], $codes );
			return;
		}
		$this->assertTrue( $result->is_valid(), self::describe( $result ) );
		$escaper  = new BasicEscaper();
		$renderer = new Renderer( $formatters, $escaper, new FormatContext( new BasicLocale(), new FixedClock( new \DateTimeImmutable( '2026-11-18T12:00:00Z' ) ), $escaper ) );
		$html     = $renderer->render( $result->template, ArrayResolver::from_fixture( $input['data'] ) );

		$this->assertSame( $expect['html'], $html );
		if ( isset( $expect['css'] ) ) {
			$this->assertSame( $expect['css'], $result->template->css );
		}
	}

	private static function describe( \SW\Postlist\TemplateEngine\CompileResult $result ): string {
		return (string) json_encode( array_map( static fn ( $error ): array => $error->to_array(), $result->errors ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free unit test.
	}
}
