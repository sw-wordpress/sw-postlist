<?php
/**
 * Validated set of filter types: the built-in ones plus third-party registrations (`sw_postlist_filter_types`). Faulty
 * registrations are reported in the diagnostics instead of causing an error.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Filter;

use SW\Postlist\Application\Document\OptionSchema;

final class FilterTypeRegistry {

	/** @var array<string, FilterTypeDefinition> */
	private array $types = array();

	/** @var list<array{code: string, message: string, source: string}> */
	private array $diagnostics = array();

	/**
	 * @param mixed $extensions Value returned by the `sw_postlist_filter_types` filter (untrusted): list of definitions.
	 */
	public function __construct( mixed $extensions = array() ) {
		foreach ( BuiltinFilterTypes::all() as $type ) {
			$this->types[ $type->id ] = $type;
		}
		if ( ! is_array( $extensions ) ) {
			$this->diagnose( 'filter_types_not_array', 'The sw_postlist_filter_types filter must return an array.', gettype( $extensions ) );
			return;
		}
		foreach ( $extensions as $candidate ) {
			$this->register( $candidate );
		}
	}

	public function get( string $id ): ?FilterTypeDefinition {
		return $this->types[ $id ] ?? null;
	}

	/**
	 * @return array<string, FilterTypeDefinition>
	 */
	public function all(): array {
		return $this->types;
	}

	public function is_builtin( string $id ): bool {
		return in_array( $id, BuiltinFilterTypes::IDS, true );
	}

	/**
	 * @return list<array{code: string, message: string, source: string}>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	private function register( mixed $candidate ): void {
		if ( ! $candidate instanceof FilterTypeDefinition ) {
			$this->diagnose( 'filter_type_interface', 'A filter type must be a FilterTypeDefinition.', is_object( $candidate ) ? get_class( $candidate ) : gettype( $candidate ) );
			return;
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $candidate->id ) ) {
			$this->diagnose( 'filter_type_id_invalid', 'Invalid filter type ID "' . $candidate->id . '".', $candidate->id );
			return;
		}
		if ( isset( $this->types[ $candidate->id ] ) ) {
			$this->diagnose( 'filter_type_id_duplicate', 'Duplicate filter type ID "' . $candidate->id . '"; the first registration is kept.', $candidate->id );
			return;
		}
		if ( '' === trim( $candidate->label ) ) {
			$this->diagnose( 'filter_type_label_empty', 'The filter type "' . $candidate->id . '" has no label.', $candidate->id );
			return;
		}
		$problem = $this->schema_problem( $candidate->schema );
		if ( null !== $problem ) {
			$this->diagnose( 'filter_type_schema_invalid', 'The option schema of "' . $candidate->id . '" is invalid: ' . $problem, $candidate->id );
			return;
		}
		if ( ! is_callable( $candidate->parse_callback ) || ! is_callable( $candidate->render_callback ) ) {
			$this->diagnose( 'filter_type_callback_invalid', 'The filter type "' . $candidate->id . '" needs callable parse and render callbacks.', $candidate->id );
			return;
		}
		$this->types[ $candidate->id ] = $candidate;
	}

	/**
	 * @param array<string, array<string, mixed>> $schema Option schema.
	 */
	private function schema_problem( array $schema ): ?string {
		foreach ( $schema as $option => $spec ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,30}$/', $option ) || 'type' === $option ) {
				return 'invalid option name';
			}
			if ( ! in_array( $spec['type'] ?? null, OptionSchema::TYPES, true ) ) {
				return 'option "' . $option . '" needs a known type';
			}
			if ( in_array( $spec['type'], array( 'enum', 'enum_list' ), true ) && empty( $spec['values'] ) ) {
				return 'enum "' . $option . '" needs values';
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
