<?php
/**
 * Compiles template HTML and CSS: lexer, parser, validator, AST builder and CSS scoper in one call.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Syntax\Lexer;
use SW\Postlist\TemplateEngine\Syntax\Parser;

final class Compiler {

	/**
	 * @param int|string $template_id Identifier used for the scope class (`swp-tpl-{id}`).
	 */
	public function compile( string $template, string $css, CompileContext $context, int|string $template_id ): CompileResult {
		if ( strlen( $template ) > EngineProfile::MAX_TEMPLATE_BYTES ) {
			return new CompileResult( null, array( new TemplateError( 'too_long', 'The template is too long.' ) ) );
		}
		if ( ! mb_check_encoding( $template, 'UTF-8' ) || ! mb_check_encoding( $css, 'UTF-8' ) ) {
			return new CompileResult( null, array( new TemplateError( 'invalid_encoding', 'The template is not valid UTF-8.' ) ) );
		}
		$lexed  = ( new Lexer() )->tokenize( $template );
		$parsed = ( new Parser() )->parse( $lexed['tokens'], $lexed['source'] );
		$errors = array_merge( $lexed['errors'], $parsed['errors'] );
		if ( array() === $errors ) {
			$errors = ( new Validator() )->validate( $parsed['nodes'], $context, $lexed['source'] );
		}
		$scope_class = $context->profile->scope_class( $template_id );
		$scoped_css  = '';
		try {
			$scoped_css = '' === trim( $css ) ? '' : CssScoper::scope( $css, '.' . $scope_class );
		} catch ( \InvalidArgumentException $error ) {
			$errors[] = new TemplateError( 'invalid_css', 'The CSS is not acceptable: ' . $error->getMessage() . '.', 1, 1, array( 'reason' => $error->getMessage() ) );
		}
		if ( array() !== $errors ) {
			return new CompileResult( null, $errors );
		}
		$ast = ( new AstBuilder() )->build( $parsed['nodes'], $context );
		return new CompileResult( new CompiledTemplate( $ast, $scoped_css, $scope_class ) );
	}
}
