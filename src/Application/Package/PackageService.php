<?php
/**
 * Export and import of views, templates and presets as one JSON package (PLAN.md §7, M8).
 *
 * Package format 1: `templates` is a map of package-local keys to template documents, `views` a list of
 * `{key, status, document}` whose `templates.container|entry` hold those keys instead of IDs, `presets` a list of
 * preset documents. Every template used by an exported view is included once, however many views share it.
 *
 * Import validates everything before the first write (size and counts, format version, every document with its
 * migration, source IDs, references and roles, the template language against the source of each view). It then
 * creates templates and views as drafts, publishes the views only after every reference exists, and removes what it
 * created again when anything fails.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Package;

use SW\Postlist\Application\Document\TemplateDocumentValidator;
use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Port\PresetStoreInterface;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Application\Preset\PresetValidator;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Domain\Preset\Preset;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Domain\Validation\ValidationError;
use SW\Postlist\Domain\View\ViewDocument;

final class PackageService {

	public const FORMAT = 'sw-postlist-package';

	public const FORMAT_VERSION = 1;

	public const MAX_VIEWS = 100;

	public const MAX_TEMPLATES = 200;

	public const MAX_PRESETS = 50;

	/** Largest accepted package in bytes (checked by the caller before decoding). */
	public const MAX_BYTES = 2097152;

	/**
	 * @param \Closure(TemplateDocument, string): list<array{code: string, message: string}> $template_check Compiles a template against a source and returns the findings.
	 * @param \Closure(): string                                                             $now            Timestamp for the export (ISO 8601).
	 */
	public function __construct(
		private readonly ViewRepositoryInterface $views,
		private readonly TemplateRepositoryInterface $templates,
		private readonly PresetStoreInterface $presets,
		private readonly ViewDocumentValidator $view_validator,
		private readonly TemplateDocumentValidator $template_validator,
		private readonly PresetValidator $preset_validator,
		private readonly SourceRegistry $sources,
		private readonly \Closure $template_check,
		private readonly string $plugin_version,
		private readonly \Closure $now
	) {
	}

	/**
	 * @param list<int>    $view_ids     Views to export.
	 * @param list<int>    $template_ids Additional templates (those used by the views are always included).
	 * @param list<string> $preset_ids   Presets to export.
	 * @return array{package: array<string, mixed>, missing: list<string>}
	 */
	public function export( array $view_ids, array $template_ids = array(), array $preset_ids = array() ): array {
		$keys      = array();
		$templates = array();
		$views     = array();
		$missing   = array();
		$key_for   = function ( int $template_id ) use ( &$keys, &$templates, &$missing ): ?string {
			if ( isset( $keys[ $template_id ] ) ) {
				return $keys[ $template_id ];
			}
			$document = $this->templates->find( $template_id );
			if ( null === $document ) {
				$missing[] = 'template:' . $template_id;
				return null;
			}
			$key                  = 't' . ( count( $keys ) + 1 );
			$keys[ $template_id ] = $key;
			$templates[ $key ]    = $document->to_array();
			return $key;
		};
		foreach ( array_values( array_unique( $view_ids ) ) as $view_id ) {
			$document = $this->views->find( $view_id );
			if ( null === $document ) {
				$missing[] = 'view:' . $view_id;
				continue;
			}
			$raw                           = $document->to_array();
			$raw['templates']['container'] = null === $document->container_template_id ? null : $key_for( $document->container_template_id );
			$raw['templates']['entry']     = null === $document->entry_template_id ? null : $key_for( $document->entry_template_id );
			$views[]                       = array(
				'key'      => 'v' . ( count( $views ) + 1 ),
				'status'   => 'draft' === $this->views->status( $view_id ) ? 'draft' : 'publish',
				'document' => $raw,
			);
		}
		foreach ( array_values( array_unique( $template_ids ) ) as $template_id ) {
			$key_for( $template_id );
		}
		$presets = array();
		foreach ( array_values( array_unique( $preset_ids ) ) as $preset_id ) {
			$preset = $this->presets->export( $preset_id );
			if ( null === $preset ) {
				$missing[] = 'preset:' . $preset_id;
				continue;
			}
			$presets[] = $preset;
		}
		return array(
			'package' => array(
				'format'         => self::FORMAT,
				'format_version' => self::FORMAT_VERSION,
				'plugin_version' => $this->plugin_version,
				'exported_at'    => ( $this->now )(),
				'templates'      => (object) $templates,
				'views'          => $views,
				'presets'        => $presets,
			),
			'missing' => $missing,
		);
	}

	/**
	 * @param mixed $package Decoded package (untrusted).
	 */
	public function import( mixed $package, bool $dry_run = false ): PackageReport {
		$errors = array();
		$plan   = $this->plan( $package, $errors );
		$counts = array(
			'views'     => count( $plan['views'] ),
			'templates' => count( $plan['templates'] ),
			'presets'   => count( $plan['presets'] ),
		);
		if ( array() !== $errors ) {
			return new PackageReport( false, $errors, $counts, dry_run: $dry_run );
		}
		if ( $dry_run ) {
			return new PackageReport( true, array(), $counts, dry_run: true );
		}
		return $this->write( $plan, $counts );
	}

	/**
	 * Validates the whole package; nothing is written.
	 *
	 * @param mixed                                                    $package Decoded package.
	 * @param list<array{code: string, path: string, message: string}> $errors  Collected findings.
	 * @return array{templates: array<string, TemplateDocument>, views: list<array{key: string, status: string, document: ViewDocument, refs: array{container: ?string, entry: ?string}}>, presets: list<Preset>}
	 */
	private function plan( mixed $package, array &$errors ): array {
		$plan = array(
			'templates' => array(),
			'views'     => array(),
			'presets'   => array(),
		);
		if ( ! is_array( $package ) || self::FORMAT !== ( $package['format'] ?? null ) ) {
			$errors[] = $this->error( 'package_format_invalid', 'format', 'This is not a Postlist export package.' );
			return $plan;
		}
		$version = $package['format_version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			$errors[] = $this->error( 'package_version_invalid', 'format_version', 'The package version is missing or invalid.' );
			return $plan;
		}
		if ( $version > self::FORMAT_VERSION ) {
			$errors[] = $this->error( 'package_version_unsupported', 'format_version', 'The package was created by a newer version of Postlist. Update the plugin and try again.' );
			return $plan;
		}
		$raw_templates = $package['templates'] ?? array();
		$raw_views     = $package['views'] ?? array();
		$raw_presets   = $package['presets'] ?? array();
		if ( ! is_array( $raw_templates ) || ! is_array( $raw_views ) || ! is_array( $raw_presets ) ) {
			$errors[] = $this->error( 'package_sections_invalid', '', 'The sections templates, views and presets must be lists or maps.' );
			return $plan;
		}
		if ( count( $raw_views ) > self::MAX_VIEWS || count( $raw_templates ) > self::MAX_TEMPLATES || count( $raw_presets ) > self::MAX_PRESETS ) {
			$errors[] = $this->error( 'package_too_large', '', 'The package contains too many entries.' );
			return $plan;
		}
		if ( array() === $raw_views && array() === $raw_templates && array() === $raw_presets ) {
			$errors[] = $this->error( 'package_empty', '', 'The package contains nothing to import.' );
			return $plan;
		}
		$plan['templates'] = $this->plan_templates( $raw_templates, $errors );
		$plan['views']     = $this->plan_views( $raw_views, $plan['templates'], $errors );
		$plan['presets']   = $this->plan_presets( $raw_presets, $errors );
		return $plan;
	}

	/**
	 * @param array<mixed>                                             $raw    Raw templates by key.
	 * @param list<array{code: string, path: string, message: string}> $errors Collected findings.
	 * @return array<string, TemplateDocument>
	 */
	private function plan_templates( array $raw, array &$errors ): array {
		$out = array();
		foreach ( $raw as $key => $document ) {
			$path = 'templates.' . $key;
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $key ) || ! is_array( $document ) ) {
				$errors[] = $this->error( 'template_key_invalid', $path, 'Template keys are short lowercase names and map to documents.' );
				continue;
			}
			$result = $this->template_validator->validate( $document );
			if ( ! $result->is_valid() ) {
				foreach ( $result->errors() as $error ) {
					$errors[] = $this->error( $error->code, $path . '.' . $error->path, $error->message );
				}
				continue;
			}
			$out[ $key ] = $result->value();
		}
		return $out;
	}

	/**
	 * @param array<mixed>                                             $raw       Raw view entries.
	 * @param array<string, TemplateDocument>                          $templates Validated templates by key.
	 * @param list<array{code: string, path: string, message: string}> $errors    Collected findings.
	 * @return list<array{key: string, status: string, document: ViewDocument, refs: array{container: ?string, entry: ?string}}>
	 */
	private function plan_views( array $raw, array $templates, array &$errors ): array {
		$out  = array();
		$used = array();
		foreach ( array_values( $raw ) as $index => $entry ) {
			$path     = 'views.' . $index;
			$document = is_array( $entry ) && is_array( $entry['document'] ?? null ) ? $entry['document'] : null;
			if ( null === $document ) {
				$errors[] = $this->error( 'view_entry_invalid', $path, 'A view entry needs a document.' );
				continue;
			}
			$status = 'draft' === ( $entry['status'] ?? null ) ? 'draft' : 'publish';
			$refs   = array(
				'container' => null,
				'entry'     => null,
			);
			$before = count( $errors );
			foreach ( array( 'container', 'entry' ) as $role ) {
				$ref = is_array( $document['templates'] ?? null ) ? ( $document['templates'][ $role ] ?? null ) : null;
				if ( null === $ref ) {
					continue;
				}
				if ( ! is_string( $ref ) || ! isset( $templates[ $ref ] ) ) {
					$errors[] = $this->error( 'template_reference_missing', $path . '.templates.' . $role, 'The view refers to a template that is not part of the package.' );
					continue;
				}
				if ( TemplateRole::from( $role ) !== $templates[ $ref ]->role ) {
					$errors[] = $this->error( 'template_role_mismatch', $path . '.templates.' . $role, 'The referenced template has the wrong role.' );
					continue;
				}
				$refs[ $role ]                  = $ref;
				$used[ $ref ][]                 = $path;
				$document['templates'][ $role ] = 1;
			}
			if ( count( $errors ) > $before ) {
				continue;
			}
			$result = $this->view_validator->validate( $document );
			if ( ! $result->is_valid() ) {
				foreach ( $result->errors() as $error ) {
					$errors[] = $this->error( $error->code, $path . '.' . $error->path, $error->message );
				}
				continue;
			}
			$value = $result->value();
			if ( null === $this->sources->get( $value->source_id ) ) {
				$errors[] = $this->error( 'source_unknown', $path . '.source', 'The entry source "' . $value->source_id . '" is not available on this site.' );
				continue;
			}
			foreach ( array( 'container', 'entry' ) as $role ) {
				if ( null !== $refs[ $role ] ) {
					foreach ( ( $this->template_check )( $templates[ $refs[ $role ] ], $value->source_id ) as $finding ) {
						$errors[] = $this->error( $finding['code'], $path . '.templates.' . $role, $finding['message'] );
					}
				}
			}
			$out[] = array(
				'key'      => is_string( $entry['key'] ?? null ) ? $entry['key'] : 'v' . ( $index + 1 ),
				'status'   => $status,
				'document' => $value,
				'refs'     => $refs,
			);
		}
		return $out;
	}

	/**
	 * @param array<mixed>                                             $raw    Raw presets.
	 * @param list<array{code: string, path: string, message: string}> $errors Collected findings.
	 * @return list<Preset>
	 */
	private function plan_presets( array $raw, array &$errors ): array {
		$out  = array();
		$seen = array();
		foreach ( array_values( $raw ) as $index => $document ) {
			$path = 'presets.' . $index;
			if ( ! is_array( $document ) ) {
				$errors[] = $this->error( 'preset_invalid', $path, 'A preset must be an object.' );
				continue;
			}
			$result = $this->preset_validator->validate( $document );
			if ( ! $result->is_valid() ) {
				foreach ( $result->errors() as $error ) {
					$errors[] = $this->error( $error->code, $path . '.' . $error->path, $error->message );
				}
				continue;
			}
			$value = $result->value();
			if ( isset( $seen[ $value->id ] ) ) {
				$errors[] = $this->error( 'preset_id_duplicate', $path . '.id', 'The package contains this preset twice.' );
				continue;
			}
			$seen[ $value->id ] = true;
			$out[]              = $value;
		}
		return $out;
	}

	/**
	 * @param array{templates: array<string, TemplateDocument>, views: list<array{key: string, status: string, document: ViewDocument, refs: array{container: ?string, entry: ?string}}>, presets: list<Preset>} $plan   Validated package.
	 * @param array{views: int, templates: int, presets: int}                                                                                                                                                    $counts Counts of the package.
	 */
	private function write( array $plan, array $counts ): PackageReport {
		$created = array(
			'views'     => array(),
			'templates' => array(),
			'presets'   => array(),
		);
		$renamed = array();
		$ids     = array();
		try {
			$template_titles = array_column( $this->templates->summaries(), 'title' );
			foreach ( $plan['templates'] as $key => $document ) {
				$title = $this->unique_title( $document->title, $template_titles );
				if ( $title !== $document->title ) {
					$renamed[] = $document->title . ' → ' . $title;
				}
				$template_titles[]      = $title;
				$ids[ $key ]            = $this->templates->save( null, new TemplateDocument( $title, $document->role, $document->html, $document->css ) );
				$created['templates'][] = $ids[ $key ];
			}
			$view_titles = array_column( $this->views->summaries(), 'title' );
			$drafts      = array();
			foreach ( $plan['views'] as $view ) {
				$source = $view['document'];
				$title  = $this->unique_title( $source->title, $view_titles );
				if ( $title !== $source->title ) {
					$renamed[] = $source->title . ' → ' . $title;
				}
				$view_titles[]      = $title;
				$document           = new ViewDocument(
					$title,
					$source->source_id,
					$source->query,
					$source->layout,
					$source->pagination,
					null === $view['refs']['container'] ? null : $ids[ $view['refs']['container'] ],
					null === $view['refs']['entry'] ? null : $ids[ $view['refs']['entry'] ],
					$source->filters
				);
				$view_id            = $this->views->save( null, $document, 'draft' );
				$created['views'][] = $view_id;
				$drafts[]           = array( $view_id, $document, $view['status'] );
			}
			// Every reference exists now: only then do views go live.
			foreach ( $drafts as list( $view_id, $document, $status ) ) {
				if ( 'publish' === $status ) {
					$this->views->save( $view_id, $document, 'publish' );
				}
			}
			foreach ( $plan['presets'] as $preset ) {
				$id = $this->unique_id( $preset->id );
				if ( $id !== $preset->id ) {
					$renamed[] = $preset->id . ' → ' . $id;
				}
				$this->presets->save_user( new Preset( $id, $preset->title, $preset->description, $preset->layout, $preset->container, $preset->entry, $preset->builder ) );
				$created['presets'][] = $id;
			}
		} catch ( \Throwable $failure ) {
			$this->roll_back( $created );
			return new PackageReport( false, array( $this->error( 'import_failed', '', 'The import failed and was undone: ' . $failure->getMessage() ) ), $counts );
		}
		return new PackageReport( true, array(), $counts, $created, $renamed );
	}

	/**
	 * Views first (a template in use cannot be trashed), then templates and presets.
	 *
	 * @param array{views: list<int>, templates: list<int>, presets: list<string>} $created What was created so far.
	 */
	private function roll_back( array $created ): void {
		foreach ( $created['views'] as $view_id ) {
			$this->views->trash( $view_id );
		}
		foreach ( $created['templates'] as $template_id ) {
			$this->templates->trash( $template_id );
		}
		foreach ( $created['presets'] as $preset_id ) {
			$this->presets->delete_user( $preset_id );
		}
	}

	/**
	 * @param list<string> $existing Titles in use.
	 */
	private function unique_title( string $title, array $existing ): string {
		if ( ! in_array( $title, $existing, true ) ) {
			return $title;
		}
		for ( $number = 2; $number < 1000; ++$number ) {
			$candidate = $title . ' (' . $number . ')';
			if ( ! in_array( $candidate, $existing, true ) ) {
				return $candidate;
			}
		}
		return $title;
	}

	private function unique_id( string $id ): string {
		if ( ! $this->presets->has( $id ) ) {
			return $id;
		}
		for ( $number = 2; $number < 1000; ++$number ) {
			$suffix    = '-' . $number;
			$candidate = substr( $id, 0, 40 - strlen( $suffix ) ) . $suffix;
			if ( ! $this->presets->has( $candidate ) ) {
				return $candidate;
			}
		}
		return $id;
	}

	/**
	 * @return array{code: string, path: string, message: string}
	 */
	private function error( string $code, string $path, string $message ): array {
		return ( new ValidationError( $code, $path, $message ) )->to_array();
	}
}
