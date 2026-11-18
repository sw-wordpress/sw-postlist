<?php
/**
 * Validated set of entry sources. Faulty registrations never cause a fatal error; they end up in the diagnostics.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Query;

use SW\Postlist\Application\Port\EntrySourceInterface;

final class SourceRegistry {

	public const COUNT_MODES = array( 'exact', 'continuation' );

	/** @var array<string, EntrySourceInterface> */
	private array $sources = array();

	/** @var list<array{code: string, message: string, source: string}> */
	private array $diagnostics = array();

	/**
	 * @param mixed $candidates Value returned by the `sw_postlist_entry_sources` filter (untrusted).
	 */
	public function __construct( mixed $candidates ) {
		if ( ! is_array( $candidates ) ) {
			$this->diagnose( 'sources_not_array', 'The sw_postlist_entry_sources filter must return an array.', gettype( $candidates ) );
			return;
		}
		foreach ( $candidates as $candidate ) {
			$this->register( $candidate );
		}
	}

	public function get( string $source_id ): ?EntrySourceInterface {
		return $this->sources[ $source_id ] ?? null;
	}

	/**
	 * @return array<string, EntrySourceInterface>
	 */
	public function all(): array {
		return $this->sources;
	}

	/**
	 * @return list<array{code: string, message: string, source: string}>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	private function register( mixed $candidate ): void {
		if ( ! $candidate instanceof EntrySourceInterface ) {
			$this->diagnose( 'source_interface', 'An entry source must implement EntrySourceInterface.', is_object( $candidate ) ? get_class( $candidate ) : gettype( $candidate ) );
			return;
		}
		$label = get_class( $candidate );
		try {
			$id     = $candidate->id();
			$fields = $candidate->query_fields();
			$name   = $candidate->label();
		} catch ( \Throwable $error ) {
			$this->diagnose( 'source_failed', 'The entry source threw while it was registered: ' . $error->getMessage(), $label );
			return;
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]+(?::[a-z0-9_-]+)*$/', $id ) || 'any' === $id || 'post:any' === $id ) {
			$this->diagnose( 'source_id_invalid', 'Invalid entry source ID "' . $id . '".', $label );
			return;
		}
		if ( isset( $this->sources[ $id ] ) ) {
			$this->diagnose( 'source_id_duplicate', 'Duplicate entry source ID "' . $id . '"; the first registration is kept.', $label );
			return;
		}
		if ( '' === trim( $name ) ) {
			$this->diagnose( 'source_label_empty', 'The entry source "' . $id . '" has no label.', $label );
			return;
		}
		$problem = $this->schema_problem( $fields );
		if ( null !== $problem ) {
			$this->diagnose( 'source_schema_invalid', 'The query fields of "' . $id . '" are invalid: ' . $problem, $label );
			return;
		}
		$this->sources[ $id ] = $candidate;
	}

	/**
	 * @param array<string, mixed> $fields Declared query fields.
	 */
	private function schema_problem( array $fields ): ?string {
		if ( ! in_array( $fields['count_mode'] ?? null, self::COUNT_MODES, true ) ) {
			return 'count_mode must be "exact" or "continuation"';
		}
		foreach ( array( 'taxonomies', 'orderby', 'visitor_filters' ) as $key ) {
			$list = $fields[ $key ] ?? null;
			if ( ! is_array( $list ) || array() !== array_filter( $list, static fn ( mixed $item ): bool => ! is_string( $item ) ) ) {
				return $key . ' must be a list of strings';
			}
		}
		return null;
	}

	private function diagnose( string $code, string $message, string $source ): void {
		$this->diagnostics[] = array(
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}
}
