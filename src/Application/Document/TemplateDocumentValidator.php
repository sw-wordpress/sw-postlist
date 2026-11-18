<?php
/**
 * Validates and normalizes raw template documents. Template language checks arrive with the engine (M2).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Document;

use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Domain\Validation\ValidationError;
use SW\Postlist\Domain\Validation\ValidationResult;

final class TemplateDocumentValidator {

	public const MAX_HTML_BYTES = 200000;
	public const MAX_CSS_BYTES  = 100000;

	private DocumentMigrator $migrator;

	public function __construct( ?DocumentMigrator $migrator = null ) {
		$this->migrator = $migrator ?? new DocumentMigrator( TemplateDocument::SCHEMA_VERSION );
	}

	/**
	 * @param array<string, mixed> $raw Raw document.
	 * @return ValidationResult<TemplateDocument>
	 */
	public function validate( array $raw ): ValidationResult {
		$migrated = $this->migrator->migrate( $raw );
		if ( ! $migrated->is_valid() ) {
			return ValidationResult::invalid( $migrated->errors() );
		}
		$document = $migrated->value();
		$errors   = array();

		$title = $document['title'] ?? null;
		if ( ! is_string( $title ) || '' === trim( $title ) || mb_strlen( $title ) > 200 ) {
			$errors[] = new ValidationError( 'title_invalid', 'title', 'The title must be a non-empty string of at most 200 characters.' );
			$title    = '';
		}
		$role_name = $document['role'] ?? null;
		$role      = is_string( $role_name ) ? TemplateRole::tryFrom( $role_name ) : null;
		if ( null === $role ) {
			$errors[] = new ValidationError( 'role_invalid', 'role', 'The role must be container or entry.' );
		}
		$html = $document['html'] ?? null;
		if ( ! is_string( $html ) || strlen( $html ) > self::MAX_HTML_BYTES ) {
			$errors[] = new ValidationError( 'html_invalid', 'html', 'The template HTML must be a string within the size limit.' );
			$html     = '';
		}
		$css = $document['css'] ?? '';
		if ( ! is_string( $css ) || strlen( $css ) > self::MAX_CSS_BYTES ) {
			$errors[] = new ValidationError( 'css_invalid', 'css', 'The template CSS must be a string within the size limit.' );
			$css      = '';
		}
		if ( array() !== $errors ) {
			return ValidationResult::invalid( $errors );
		}
		return ValidationResult::valid( new TemplateDocument( trim( $title ), $role, $html, $css ) );
	}
}
