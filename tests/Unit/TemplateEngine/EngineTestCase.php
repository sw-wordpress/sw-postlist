<?php
/**
 * Shared setup for template engine tests.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit\TemplateEngine;

use PHPUnit\Framework\TestCase;
use SW\Postlist\TemplateEngine\CompileContext;
use SW\Postlist\TemplateEngine\CompileResult;
use SW\Postlist\TemplateEngine\Compiler;
use SW\Postlist\TemplateEngine\EngineProfile;
use SW\Postlist\TemplateEngine\Formatter\BuiltinFormatters;
use SW\Postlist\TemplateEngine\Formatter\FormatterRegistry;
use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\Port\ValueResolverInterface;
use SW\Postlist\TemplateEngine\Renderer;
use SW\Postlist\Tests\Support\Engine\ArraySchema;
use SW\Postlist\Tests\Support\Engine\BasicEscaper;
use SW\Postlist\Tests\Support\Engine\BasicLocale;
use SW\Postlist\Tests\Support\Engine\FixedClock;

abstract class EngineTestCase extends TestCase {

	protected function formatters(): FormatterRegistry {
		return new FormatterRegistry( BuiltinFormatters::all() );
	}

	protected function context( string $role = 'entry', ?EngineProfile $profile = null ): CompileContext {
		return new CompileContext( $profile ?? EngineProfile::postlist(), ArraySchema::contract(), $this->formatters(), $role );
	}

	protected function compile( string $template, string $css = '', string $role = 'entry' ): CompileResult {
		return ( new Compiler() )->compile( $template, $css, $this->context( $role ), 7 );
	}

	protected function renderer( ?\DateTimeImmutable $now = null ): Renderer {
		$escaper = new BasicEscaper();
		return new Renderer( $this->formatters(), $escaper, new FormatContext( new BasicLocale(), new FixedClock( $now ?? new \DateTimeImmutable( '2026-11-18T12:00:00Z' ) ), $escaper ) );
	}

	protected function render( string $template, ValueResolverInterface $resolver, ?\DateTimeImmutable $now = null ): string {
		$result = $this->compile( $template );
		$this->assertTrue( $result->is_valid(), implode( ',', $result->error_codes() ) );
		return $this->renderer( $now )->render( $result->template, $resolver );
	}
}
