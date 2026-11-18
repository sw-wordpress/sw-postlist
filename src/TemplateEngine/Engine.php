<?php
/**
 * Entry point of the template engine: compiles with an optional AST cache and renders.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Port\AstCacheInterface;

final class Engine {

	private Compiler $compiler;

	public function __construct( private readonly ?AstCacheInterface $cache = null, ?Compiler $compiler = null ) {
		$this->compiler = $compiler ?? new Compiler();
	}

	/**
	 * @param int|string $template_id Scope identifier of the template.
	 * @param string     $generations Host invalidation state (cache generations of the source and the template).
	 */
	public function compile( string $template, string $css, CompileContext $context, int|string $template_id, string $generations = '' ): CompileResult {
		if ( null === $this->cache ) {
			return $this->compiler->compile( $template, $css, $context, $template_id );
		}
		$key    = AstCacheKey::build( $template, $css, $context, $template_id, $generations );
		$stored = $this->cache->get( $key );
		if ( null !== $stored ) {
			$compiled = CompiledTemplate::from_array( $stored );
			if ( null !== $compiled ) {
				return new CompileResult( $compiled );
			}
		}
		$result = $this->compiler->compile( $template, $css, $context, $template_id );
		if ( null !== $result->template ) {
			$this->cache->set( $key, $result->template->to_array() );
		}
		return $result;
	}
}
