<?php
/**
 * Compiles a stored template for an entry source: schema, formatters and cache generations are applied.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\TemplateEngine\CompileResult;
use SW\Postlist\TemplateEngine\TemplateError;

final class TemplateCompilation {

	public function __construct(
		private readonly TemplateRepositoryInterface $templates,
		private readonly TemplateEngineFactory $factory
	) {
	}

	/** Compiles the published version of a template (AST cache applies). */
	public function compile( int $template_id, EntrySourceInterface $source ): CompileResult {
		$document = $this->templates->find( $template_id );
		if ( null === $document ) {
			return new CompileResult( null, array( new TemplateError( 'template_missing', 'The template does not exist.' ) ) );
		}
		return $this->factory->engine()->compile(
			$document->html,
			$document->css,
			$this->factory->context( $source, $document->role->value ),
			$template_id,
			$this->factory->generations( $source, $template_id )
		);
	}

	/**
	 * Validates a template document (for example a draft in the editor) without caching: syntax, allowlist,
	 * placeholders, formatters and CSS.
	 */
	public function validate( TemplateDocument $document, EntrySourceInterface $source ): CompileResult {
		return ( new \SW\Postlist\TemplateEngine\Compiler() )->compile( $document->html, $document->css, $this->factory->context( $source, $document->role->value ), 0 );
	}
}
