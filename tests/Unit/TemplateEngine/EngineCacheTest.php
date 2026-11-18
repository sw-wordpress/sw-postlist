<?php
/**
 * AST cache: hits, key sensitivity and damaged entries.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit\TemplateEngine;

use SW\Postlist\TemplateEngine\CompiledTemplate;
use SW\Postlist\TemplateEngine\CompileContext;
use SW\Postlist\TemplateEngine\Engine;
use SW\Postlist\TemplateEngine\EngineProfile;
use SW\Postlist\TemplateEngine\Formatter\BuiltinFormatters;
use SW\Postlist\TemplateEngine\Formatter\FormatterRegistry;
use SW\Postlist\TemplateEngine\PlaceholderDefinition;
use SW\Postlist\TemplateEngine\ValueType;
use SW\Postlist\Tests\Support\Engine\ArrayAstCache;
use SW\Postlist\Tests\Support\Engine\ArraySchema;

final class EngineCacheTest extends EngineTestCase {

	public function test_second_compile_is_a_cache_hit_with_identical_result(): void {
		$cache  = new ArrayAstCache();
		$engine = new Engine( $cache );
		$first  = $engine->compile( '<p>{{entry.title|upper}}</p>', '.a{color:red}', $this->context(), 7 );
		$second = $engine->compile( '<p>{{entry.title|upper}}</p>', '.a{color:red}', $this->context(), 7 );

		$this->assertTrue( $first->is_valid() );
		$this->assertSame( 1, $cache->writes );
		$this->assertSame( 1, $cache->hits );
		$this->assertSame( $first->template->to_array(), $second->template->to_array() );
	}

	public function test_errors_are_not_cached(): void {
		$cache  = new ArrayAstCache();
		$engine = new Engine( $cache );

		$this->assertFalse( $engine->compile( '{{entry.nope}}', '', $this->context(), 7 )->is_valid() );
		$this->assertSame( 0, $cache->writes );
	}

	public function test_every_input_of_the_compiled_result_changes_the_key(): void {
		$cache  = new ArrayAstCache();
		$engine = new Engine( $cache );
		$base   = fn ( string $template = '<p>{{view.title}}</p>', string $css = '', ?CompileContext $context = null, int $id = 7, string $generations = 'g1' ) => $engine->compile( $template, $css, $context ?? $this->context(), $id, $generations );

		$base();
		$base( '<p>{{view.title}}!</p>' );
		$base( '<p>{{view.title}}</p>', '.a{color:red}' );
		$base( '<p>{{view.title}}</p>', '', $this->context( 'container' ) );
		$base( '<p>{{view.title}}</p>', '', $this->context( 'entry', EngineProfile::cloudcalendar() ) );
		$base( '<p>{{view.title}}</p>', '', new CompileContext( EngineProfile::postlist(), new ArraySchema( array( new PlaceholderDefinition( 'view.title', ValueType::Text, 'changed' ) ) ), $this->formatters(), 'entry' ) );
		$base( '<p>{{view.title}}</p>', '', new CompileContext( EngineProfile::postlist(), ArraySchema::contract(), new FormatterRegistry( array() ), 'entry' ) );
		$base( '<p>{{view.title}}</p>', '', null, 8 );
		$base( '<p>{{view.title}}</p>', '', null, 7, 'g2' );

		$this->assertSame( 9, $cache->writes, 'Nine different inputs, nine different keys.' );
		$this->assertSame( 0, $cache->hits );

		$base();
		$this->assertSame( 1, $cache->hits, 'Identical inputs hit the cache.' );
	}

	public function test_damaged_entries_are_recompiled(): void {
		$cache  = new ArrayAstCache();
		$engine = new Engine( $cache );
		$engine->compile( '<p>{{view.title}}</p>', '', $this->context(), 7 );
		$key = array_key_first( $cache->entries );

		foreach (
			array(
				array( 'garbage' => true ),
				array(
					'engine' => '0',
					'ast'    => array(),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => 'no',
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 'x', 'unknown node' ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 't', 5 ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 'v', 'bad name', 'text', array(), 'text' ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 'v', 'entry.title', 'html', array(), 'text' ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 'v', 'entry.title', 'text', array( array( 'upper', 'not a list' ) ), 'text' ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 'v', 'entry.title', 'text', array(), 'bogus' ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 's', 'entry.title', 'yes', array() ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( array( 'u', 'On Click', 'entry.url', array(), 'url' ) ),
					'css'    => '',
					'scope'  => 'x',
				),
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array(),
					'css'    => '',
					'scope'  => '../etc',
				),
			) as $payload
		) {
			$cache->entries[ $key ] = $payload;
			$writes                 = $cache->writes;

			$result = $engine->compile( '<p>{{view.title}}</p>', '', $this->context(), 7 );

			$this->assertTrue( $result->is_valid() );
			$this->assertSame( $writes + 1, $cache->writes, 'A damaged entry is replaced by a fresh compilation.' );
			$this->assertSame( '<p>', $result->template->ast[0][1] );
		}
	}

	public function test_deeply_nested_payloads_are_rejected(): void {
		$node = array( 't', 'x' );
		for ( $level = 0; $level < 80; $level++ ) {
			$node = array( 's', 'entry.featured', false, array( $node ) );
		}

		$this->assertNull(
			CompiledTemplate::from_array(
				array(
					'engine' => EngineProfile::ENGINE_VERSION,
					'ast'    => array( $node ),
					'css'    => '',
					'scope'  => 'x',
				)
			)
		);
	}

	public function test_valid_payload_round_trips(): void {
		$result = $this->compile( '{{#entry.featured}}<a href="{{entry.url}}" class="{{entry.title|upper}}">{{entry.excerpt|truncate:3}}</a>{{/entry.featured}}', '.a{color:red}' );

		$this->assertSame( $result->template->to_array(), CompiledTemplate::from_array( $result->template->to_array() )?->to_array() );
	}

	public function test_oversized_or_invalid_input_is_rejected_before_compilation(): void {
		$this->assertSame( array( 'too_long' ), $this->compile( str_repeat( 'a', EngineProfile::MAX_TEMPLATE_BYTES + 1 ) )->error_codes() );
		$this->assertSame( array( 'invalid_encoding' ), $this->compile( "bad \xB1 bytes" )->error_codes() );
		$this->assertSame( array( 'invalid_css' ), $this->compile( '<p>x</p>', str_repeat( 'a', EngineProfile::MAX_CSS_BYTES + 1 ) )->error_codes() );
	}

	public function test_nesting_depth_is_limited(): void {
		$deep = str_repeat( '<div>', EngineProfile::MAX_DEPTH + 5 ) . str_repeat( '</div>', EngineProfile::MAX_DEPTH + 5 );

		$this->assertContains( 'too_deep', $this->compile( $deep )->error_codes() );
	}

	public function test_profiles_scope_class_and_allowlist(): void {
		$this->assertSame( 'swp-tpl-12', EngineProfile::postlist()->scope_class( 12 ) );
		$this->assertSame( 'swc-tpl-12', EngineProfile::cloudcalendar()->scope_class( 12 ) );
		$this->assertNotSame( EngineProfile::postlist()->fingerprint(), EngineProfile::cloudcalendar()->fingerprint() );
		$this->assertTrue( EngineProfile::postlist()->allows_attribute( 'div', 'data-foo-bar' ) );
		$this->assertTrue( EngineProfile::postlist()->allows_attribute( 'div', 'aria-label' ) );
		$this->assertFalse( EngineProfile::postlist()->allows_attribute( 'div', 'data-' ) );
		$this->assertFalse( EngineProfile::postlist()->allows_attribute( 'div', 'srcdoc' ) );
		$custom = new EngineProfile( array( 'p' ), array( 'class' ), array(), 'x-', true );
		$this->assertFalse( ( new \SW\Postlist\TemplateEngine\Compiler() )->compile( '<div>x</div>', '', new CompileContext( $custom, ArraySchema::contract(), new FormatterRegistry( BuiltinFormatters::all() ), 'entry' ), 1 )->is_valid() );
	}
}
