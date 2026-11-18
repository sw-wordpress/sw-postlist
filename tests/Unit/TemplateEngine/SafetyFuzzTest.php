<?php
/**
 * Deterministic fuzzing of the compile-and-render pipeline: whatever compiles must render without active content, and
 * hostile values must never break out of their context (XSS, attribute and style injection).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit\TemplateEngine;

use SW\Postlist\TemplateEngine\ImageValue;
use SW\Postlist\TemplateEngine\ListItem;
use SW\Postlist\Tests\Support\Engine\ArrayResolver;

final class SafetyFuzzTest extends EngineTestCase {

	private int $seed = 18112026;

	private function pick( array $items ): mixed {
		$this->seed = ( $this->seed * 1103515245 + 12345 ) & 0x7fffffff;
		return $items[ $this->seed % count( $items ) ];
	}

	private function template(): string {
		$fragments = array(
			'<p>{{entry.title}}</p>',
			'<p class="a {{#entry.featured}}x{{/entry.featured}}">{{entry.excerpt|nl2br}}</p>',
			'<a href="{{entry.url}}">{{entry.title|upper|truncate:20}}</a>',
			'<a href="{{entry.meta.subtitle|esc_url}}" title="{{entry.title}}">l</a>',
			'<a href="https://example.test/p?a=1&amp;b=2">x</a>',
			'<img src="{{entry.image}}" alt="{{entry.title}}">',
			'<span title="{{entry.title}}">{{entry.terms.category|link}}</span>',
			'<ul><li>{{entry.terms.category|join:" | "}}</li></ul>',
			'{{#entry.featured}}<b>{{entry.title}}</b>{{/entry.featured}}',
			'{{^entry.excerpt}}none{{/entry.excerpt}}',
			'<div style="color: {{entry.color}}">c</div>',
			'<div data-x="{{entry.title}}" aria-label="{{entry.excerpt}}">d</div>',
			'plain text ',
			'&lt;b&gt; &#60;script&#62; ',
			'<!-- c -->',
			'<br>',
			"\n",
			'{{entry.body_html}}',
			'<h2 title="x">{{entry.excerpt|words:3|strip_tags}}</h2>',
			// Deliberately hostile or broken pieces: they must either fail to compile or stay harmless.
			'<script>alert(1)</script>',
			'<a href="javascript:alert(1)">x</a>',
			'<p onmouseover="x">y</p>',
			'{{entry.nope}}',
			'<div class="',
			'</p>',
			'<iframe src="/x"></iframe>',
			'<p style="background:url(x)">y</p>',
		);
		$out       = '';
		for ( $count = 0; $count < 5; $count++ ) {
			$out .= $this->pick( $fragments );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function hostile_values(): array {
		$payloads = array( '"><script>alert(1)</script>', "' onmouseover='alert(1)", 'javascript:alert(1)', '</style><script>x</script>', '"; background:url(//evil.test/x)', '<img src=x onerror=alert(1)>', '&lt;script&gt;', "\x00<b>" );
		return array(
			'entry.title'          => $this->pick( $payloads ),
			'entry.excerpt'        => $this->pick( $payloads ),
			'entry.url'            => $this->pick( $payloads ),
			'entry.meta.subtitle'  => $this->pick( $payloads ),
			'entry.color'          => $this->pick( $payloads ),
			'entry.featured'       => (bool) $this->pick( array( true, false ) ),
			'entry.image'          => new ImageValue( $this->pick( $payloads ), $this->pick( $payloads ) ),
			'entry.terms.category' => array( new ListItem( $this->pick( $payloads ), $this->pick( $payloads ) ), new ListItem( $this->pick( $payloads ) ) ),
		);
	}

	public function test_compiled_and_rendered_output_never_contains_active_content(): void {
		$compiled = 0;
		for ( $round = 0; $round < 1500; $round++ ) {
			$result = $this->compile( $this->template() );
			$this->assertSame( $result->is_valid(), array() === $result->errors );
			if ( ! $result->is_valid() ) {
				continue;
			}
			++$compiled;
			$html = $this->renderer()->render( $result->template, new ArrayResolver( $this->hostile_values() ) );
			$this->assertDoesNotMatchRegularExpression( '/<\s*(script|style|iframe|object|embed|link|meta|svg|math|form|base)\b/i', $html, $html );
			$this->assertDoesNotMatchRegularExpression( '/\son[a-z]+\s*=/i', $this->attributes_only( $html ), $html );
			$this->assertDoesNotMatchRegularExpression( '/(href|src)="\s*(javascript|data|vbscript):/i', $html, $html );
			$this->assertStringNotContainsString( "\x00", $html );
			$this->assertDoesNotMatchRegularExpression( '/style="[^"]*(url\(|expression\(|@import)/i', $html, $html );
		}
		$this->assertGreaterThan( 100, $compiled, 'The generator must produce enough valid templates.' );
	}

	/**
	 * Markup of the tags only (attribute context); text nodes are excluded because escaped text may mention "onx=".
	 */
	private function attributes_only( string $html ): string {
		preg_match_all( '/<[a-z](?:"[^"]*"|[^>"])*>/i', $html, $tags );
		// Quoted attribute values may legitimately contain escaped text such as "onerror=": only the markup counts.
		return implode( "\n", array_map( static fn ( string $tag ): string => (string) preg_replace( '/"[^"]*"/', '""', $tag ), $tags[0] ) );
	}

	public function test_a_hostile_value_cannot_leave_its_attribute(): void {
		$result = $this->compile( '<a title="{{entry.title}}" href="{{entry.url}}">x</a>' );
		$html   = $this->renderer()->render(
			$result->template,
			new ArrayResolver(
				array(
					'entry.title' => '" onfocus="alert(1)" x="',
					'entry.url'   => 'https://x.test/" onclick="alert(1)',
				)
			)
		);

		$this->assertSame( '<a title="&quot; onfocus=&quot;alert(1)&quot; x=&quot;">x</a>', $html, 'A URL containing a quote is not output at all.' );
	}
}
