<?php
/**
 * Validates preset documents (bundled files, user presets and registrations through `sw_postlist_presets`).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Preset;

use SW\Postlist\Application\Document\TemplateDocumentValidator;
use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Domain\Preset\Preset;
use SW\Postlist\Domain\Validation\ValidationError;
use SW\Postlist\Domain\Validation\ValidationResult;

final class PresetValidator {

	public const MAX_BUILDER_BYTES = 50000;

	public function __construct(
		private readonly ViewDocumentValidator $views,
		private readonly TemplateDocumentValidator $templates
	) {
	}

	/**
	 * @param array<mixed> $raw Raw preset.
	 * @return ValidationResult<Preset>
	 */
	public function validate( array $raw ): ValidationResult {
		$errors = array();
		if ( Preset::SCHEMA_VERSION !== ( $raw['schema_version'] ?? null ) ) {
			return ValidationResult::invalid( array( new ValidationError( 'schema_version_unsupported', 'schema_version', 'Unsupported preset version.' ) ) );
		}
		$id = $raw['id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9-]{1,40}$/', $id ) ) {
			$errors[] = new ValidationError( 'preset_id_invalid', 'id', 'The preset ID is a slug of at most 40 characters.' );
			$id       = '';
		}
		$title = $raw['title'] ?? null;
		if ( ! is_string( $title ) || '' === trim( $title ) || mb_strlen( $title ) > 100 ) {
			$errors[] = new ValidationError( 'title_invalid', 'title', 'The title must be a non-empty string of at most 100 characters.' );
			$title    = '';
		}
		$description = is_string( $raw['description'] ?? '' ) && mb_strlen( (string) ( $raw['description'] ?? '' ) ) <= 300 ? (string) ( $raw['description'] ?? '' ) : '';
		$layout      = is_array( $raw['layout'] ?? null ) ? $raw['layout'] : array();
		$errors      = array_merge( $errors, $this->layout_errors( $layout ) );
		$container   = $this->template( $raw['container'] ?? null, 'container', $errors );
		$entry       = $this->template( $raw['entry'] ?? null, 'entry', $errors );
		$builder     = $raw['builder'] ?? null;
		if ( null !== $builder && ( ! is_array( $builder ) || strlen( (string) json_encode( $builder ) ) > self::MAX_BUILDER_BYTES ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free validator.
			$errors[] = new ValidationError( 'builder_invalid', 'builder', 'The builder model must be an object of limited size.' );
			$builder  = null;
		}
		if ( array() !== $errors ) {
			return ValidationResult::invalid( $errors );
		}
		return ValidationResult::valid( new Preset( $id, trim( $title ), $description, $layout, $container, $entry, $builder ) );
	}

	/**
	 * @param array<string, mixed> $layout Layout options.
	 * @return list<ValidationError>
	 */
	private function layout_errors( array $layout ): array {
		$probe  = array(
			'schema_version' => 1,
			'title'          => 'x',
			'source'         => 'post:post',
			'query'          => array(),
			'layout'         => $layout,
			'pagination'     => array( 'mode' => 'classic' ),
			'templates'      => array(),
		);
		$result = $this->views->validate( $probe );
		return $result->is_valid() ? array() : array_values( array_filter( $result->errors(), static fn ( ValidationError $error ): bool => str_starts_with( $error->path, 'layout' ) ) );
	}

	/**
	 * @param mixed                 $value  Raw template part.
	 * @param string                $role   container|entry.
	 * @param list<ValidationError> $errors Collected findings.
	 * @return array{html: string, css: string}
	 */
	private function template( mixed $value, string $role, array &$errors ): array {
		$probe  = array(
			'schema_version' => 1,
			'title'          => 'x',
			'role'           => $role,
			'html'           => is_array( $value ) ? ( $value['html'] ?? null ) : null,
			'css'            => is_array( $value ) ? ( $value['css'] ?? '' ) : '',
		);
		$result = $this->templates->validate( $probe );
		if ( ! $result->is_valid() ) {
			foreach ( $result->errors() as $error ) {
				$errors[] = new ValidationError( $error->code, $role . '.' . $error->path, $error->message );
			}
			return array(
				'html' => '',
				'css'  => '',
			);
		}
		return array(
			'html' => $result->value()->html,
			'css'  => $result->value()->css,
		);
	}
}
