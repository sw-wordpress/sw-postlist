<?php
/**
 * Date, time and number formatters with fixed times and sample values (PLAN.md §5).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit\TemplateEngine;

use SW\Postlist\TemplateEngine\CompileContext;
use SW\Postlist\TemplateEngine\Compiler;
use SW\Postlist\TemplateEngine\EngineProfile;
use SW\Postlist\TemplateEngine\Formatter\BuiltinFormatters;
use SW\Postlist\TemplateEngine\Formatter\CallbackFormatter;
use SW\Postlist\TemplateEngine\Formatter\FormatterRegistry;
use SW\Postlist\TemplateEngine\FormatterSignature;
use SW\Postlist\TemplateEngine\PlaceholderDefinition;
use SW\Postlist\TemplateEngine\ValueType;
use SW\Postlist\Tests\Support\Engine\ArrayResolver;
use SW\Postlist\Tests\Support\Engine\ArraySchema;

final class FormatterTest extends EngineTestCase {

	private function schema(): ArraySchema {
		return new ArraySchema(
			array(
				new PlaceholderDefinition( 'entry.date', ValueType::Date ),
				new PlaceholderDefinition( 'entry.modified', ValueType::Date ),
				new PlaceholderDefinition( 'entry.price', ValueType::Number ),
				new PlaceholderDefinition( 'entry.title', ValueType::Text ),
			)
		);
	}

	private function run_template( string $template, array $values, ?\DateTimeImmutable $now = null ): string {
		$context = new CompileContext( EngineProfile::postlist(), $this->schema(), $this->formatters(), 'entry' );
		$result  = ( new Compiler() )->compile( $template, '', $context, 1 );
		$this->assertTrue( $result->is_valid(), implode( ',', $result->error_codes() ) );
		return $this->renderer( $now )->render( $result->template, new ArrayResolver( $values ) );
	}

	public function test_date_formats_with_the_given_pattern_and_the_site_default(): void {
		$date = new \DateTimeImmutable( '2026-03-09T14:05:00Z' );

		$this->assertSame( '09.03.2026', $this->run_template( '{{entry.date|date:"d.m.Y"}}', array( 'entry.date' => $date ) ) );
		$this->assertSame( '2026-03-09', $this->run_template( '{{entry.date|date}}', array( 'entry.date' => $date ) ), 'Without a pattern the locale default applies.' );
		$this->assertSame( '2026-03-09', $this->run_template( '{{entry.date}}', array( 'entry.date' => $date ) ), 'A date without formatter uses the default format too.' );
		$this->assertSame( '14:05', $this->run_template( '{{entry.date|date:"H:i"}}', array( 'entry.date' => $date ) ) );
	}

	public function test_date_ignores_values_of_the_wrong_type_and_missing_values(): void {
		$this->assertSame( '', $this->run_template( '{{entry.date|date:"Y"}}', array() ) );
		$this->assertSame( '', $this->run_template( '{{entry.date|date:"Y"}}', array( 'entry.date' => 'yesterday' ) ) );
	}

	public function test_relative_date_uses_the_clock(): void {
		$now = new \DateTimeImmutable( '2026-11-18T12:00:00Z' );

		$this->assertSame( '3 days ago', $this->run_template( '{{entry.date|relative_date}}', array( 'entry.date' => new \DateTimeImmutable( '2026-11-15T11:00:00Z' ) ), $now ) );
		$this->assertSame( '1 day ago', $this->run_template( '{{entry.date|relative_date}}', array( 'entry.date' => new \DateTimeImmutable( '2026-11-17T11:00:00Z' ) ), $now ) );
		$this->assertSame( 'in 2 days', $this->run_template( '{{entry.date|relative_date}}', array( 'entry.date' => new \DateTimeImmutable( '2026-11-20T12:00:00Z' ) ), $now ) );
	}

	public function test_number_and_currency(): void {
		$this->assertSame( '1,235', $this->run_template( '{{entry.price|number}}', array( 'entry.price' => 1234.56 ) ) );
		$this->assertSame( '1,234.56', $this->run_template( '{{entry.price|number:2}}', array( 'entry.price' => 1234.56 ) ) );
		$this->assertSame( '1,234.560', $this->run_template( '{{entry.price|number:3}}', array( 'entry.price' => 1234.56 ) ) );
		$this->assertSame( 'EUR 1,234.50', $this->run_template( '{{entry.price|currency:"EUR"}}', array( 'entry.price' => 1234.5 ) ) );
		$this->assertSame( 'USD 7.00', $this->run_template( '{{entry.price|currency:"USD"}}', array( 'entry.price' => 7 ) ) );
		$this->assertSame( '', $this->run_template( '{{entry.price|number}}', array( 'entry.price' => 'abc' ) ) );
	}

	public function test_currency_codes_are_validated_when_the_template_is_saved(): void {
		$context = new CompileContext( EngineProfile::postlist(), $this->schema(), $this->formatters(), 'entry' );

		$this->assertSame( array( 'invalid_parameter' ), ( new Compiler() )->compile( '{{entry.price|currency:"XYZ"}}', '', $context, 1 )->error_codes() );
		$this->assertSame( array( 'missing_parameter' ), ( new Compiler() )->compile( '{{entry.price|currency}}', '', $context, 1 )->error_codes() );
		$this->assertSame( array( 'incompatible_formatter' ), ( new Compiler() )->compile( '{{entry.title|number}}', '', $context, 1 )->error_codes() );
		$this->assertSame( array( 'incompatible_formatter' ), ( new Compiler() )->compile( '{{entry.price|date}}', '', $context, 1 )->error_codes() );
	}

	public function test_registry_reports_faulty_registrations(): void {
		$valid     = new CallbackFormatter( 'shout', new FormatterSignature( array( ValueType::Text ), ValueType::Text ), static fn ( mixed $value ): string => (string) $value . '!' );
		$duplicate = new CallbackFormatter( 'shout', new FormatterSignature( array( ValueType::Text ), ValueType::Text ), static fn (): string => 'x' );
		$bad_name  = new CallbackFormatter( 'Bad-Name', new FormatterSignature( array( ValueType::Text ), ValueType::Text ), static fn (): string => 'x' );
		$no_input  = new CallbackFormatter( 'noinput', new FormatterSignature( array(), ValueType::Text ), static fn (): string => 'x' );

		$registry = new FormatterRegistry( array( $valid, $duplicate, $bad_name, $no_input, 'nope', new \stdClass() ) );

		$this->assertSame( array( 'shout' ), array_keys( $registry->all() ) );
		$this->assertSame(
			array( 'formatter_duplicate', 'formatter_name_invalid', 'formatter_signature_invalid', 'formatter_interface', 'formatter_interface' ),
			array_column( $registry->diagnostics(), 'code' )
		);
		$this->assertSame( 'formatters_not_array', ( new FormatterRegistry( 'x' ) )->diagnostics()[0]['code'] );
	}

	public function test_a_custom_formatter_takes_part_in_type_checking_and_rendering(): void {
		$extra    = new CallbackFormatter( 'shout', new FormatterSignature( array( ValueType::Text ), ValueType::Text ), static fn ( mixed $value ): string => strtoupper( (string) $value ) . '!' );
		$registry = new FormatterRegistry( array_merge( BuiltinFormatters::all(), array( $extra ) ) );
		$context  = new CompileContext( EngineProfile::postlist(), $this->schema(), $registry, 'entry' );
		$result   = ( new Compiler() )->compile( '{{entry.title|shout|truncate:3}}', '', $context, 1 );

		$this->assertTrue( $result->is_valid() );
		$escaper  = new \SW\Postlist\Tests\Support\Engine\BasicEscaper();
		$renderer = new \SW\Postlist\TemplateEngine\Renderer( $registry, $escaper, new \SW\Postlist\TemplateEngine\FormatContext( new \SW\Postlist\Tests\Support\Engine\BasicLocale(), new \SW\Postlist\Tests\Support\Engine\FixedClock( new \DateTimeImmutable() ), $escaper ) );
		$this->assertSame( 'ABC…', $renderer->render( $result->template, new ArrayResolver( array( 'entry.title' => 'abcdef' ) ) ) );
		$this->assertNotSame( ( new FormatterRegistry( BuiltinFormatters::all() ) )->fingerprint(), $registry->fingerprint(), 'A new formatter changes the cache fingerprint.' );
	}

	public function test_removed_formatter_is_a_validation_error_on_the_next_compile(): void {
		$context = new CompileContext( EngineProfile::postlist(), $this->schema(), new FormatterRegistry( array() ), 'entry' );

		$this->assertSame( array( 'unknown_formatter' ), ( new Compiler() )->compile( '{{entry.title|upper}}', '', $context, 1 )->error_codes() );
	}

	public function test_a_renderer_without_the_formatter_renders_empty_instead_of_failing(): void {
		$result   = ( new Compiler() )->compile( '[{{entry.title|upper}}]', '', new CompileContext( EngineProfile::postlist(), $this->schema(), $this->formatters(), 'entry' ), 1 );
		$escaper  = new \SW\Postlist\Tests\Support\Engine\BasicEscaper();
		$renderer = new \SW\Postlist\TemplateEngine\Renderer( new FormatterRegistry( array() ), $escaper, new \SW\Postlist\TemplateEngine\FormatContext( new \SW\Postlist\Tests\Support\Engine\BasicLocale(), new \SW\Postlist\Tests\Support\Engine\FixedClock( new \DateTimeImmutable() ), $escaper ) );

		$this->assertSame( '[]', $renderer->render( $result->template, new ArrayResolver( array( 'entry.title' => 'x' ) ) ) );
	}
}
