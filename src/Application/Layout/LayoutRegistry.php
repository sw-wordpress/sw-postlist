<?php
/**
 * Validated set of layouts: the built-in ones plus third-party registrations (`sw_postlist_layouts`). Faulty
 * registrations are reported in the diagnostics instead of causing an error.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Layout;

use SW\Postlist\Application\Document\OptionSchema;

final class LayoutRegistry {

	private const SCHEMA_TYPES = OptionSchema::TYPES;

	/** @var array<string, LayoutDefinition> */
	private array $layouts = array();

	/** @var list<array{code: string, message: string, source: string}> */
	private array $diagnostics = array();

	/**
	 * @param mixed $extensions Value returned by the `sw_postlist_layouts` filter (untrusted): list of definitions.
	 */
	public function __construct( mixed $extensions = array() ) {
		foreach ( BuiltinLayouts::all() as $layout ) {
			$this->layouts[ $layout->id ] = $layout;
		}
		if ( ! is_array( $extensions ) ) {
			$this->diagnose( 'layouts_not_array', 'The sw_postlist_layouts filter must return an array.', gettype( $extensions ) );
			return;
		}
		foreach ( $extensions as $candidate ) {
			$this->register( $candidate );
		}
	}

	public function get( string $id ): ?LayoutDefinition {
		return $this->layouts[ $id ] ?? null;
	}

	/**
	 * @return array<string, LayoutDefinition>
	 */
	public function all(): array {
		return $this->layouts;
	}

	/**
	 * @return list<string>
	 */
	public function ids(): array {
		return array_keys( $this->layouts );
	}

	/**
	 * @return list<array{code: string, message: string, source: string}>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	private function register( mixed $candidate ): void {
		if ( ! $candidate instanceof LayoutDefinition ) {
			$this->diagnose( 'layout_interface', 'A layout must be a LayoutDefinition.', is_object( $candidate ) ? get_class( $candidate ) : gettype( $candidate ) );
			return;
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $candidate->id ) ) {
			$this->diagnose( 'layout_id_invalid', 'Invalid layout ID "' . $candidate->id . '".', $candidate->id );
			return;
		}
		if ( isset( $this->layouts[ $candidate->id ] ) ) {
			$this->diagnose( 'layout_id_duplicate', 'Duplicate layout ID "' . $candidate->id . '"; the first registration is kept.', $candidate->id );
			return;
		}
		if ( '' === trim( $candidate->label ) ) {
			$this->diagnose( 'layout_label_empty', 'The layout "' . $candidate->id . '" has no label.', $candidate->id );
			return;
		}
		$problem = $this->schema_problem( $candidate->schema );
		if ( null !== $problem ) {
			$this->diagnose( 'layout_schema_invalid', 'The option schema of "' . $candidate->id . '" is invalid: ' . $problem, $candidate->id );
			return;
		}
		if ( null !== $candidate->render_callback && ! is_callable( $candidate->render_callback ) ) {
			$this->diagnose( 'layout_callback_invalid', 'The render callback of "' . $candidate->id . '" is not callable.', $candidate->id );
			return;
		}
		if ( null !== $candidate->module && 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $candidate->module ) ) {
			$this->diagnose( 'layout_module_invalid', 'The module name of "' . $candidate->id . '" is invalid.', $candidate->id );
			return;
		}
		if ( ! in_array( $candidate->item_tag, array( 'div', 'li' ), true ) ) {
			$this->diagnose( 'layout_item_tag_invalid', 'The item tag of "' . $candidate->id . '" must be div or li.', $candidate->id );
			return;
		}
		$this->layouts[ $candidate->id ] = $candidate;
	}

	/**
	 * @param array<string, array<string, mixed>> $schema Option schema.
	 */
	private function schema_problem( array $schema ): ?string {
		foreach ( $schema as $option => $spec ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,30}$/', $option ) || 'type' === $option ) {
				return 'invalid option name';
			}
			if ( ! in_array( $spec['type'] ?? null, self::SCHEMA_TYPES, true ) ) {
				return 'option "' . $option . '" needs a known type';
			}
			if ( 'enum' === $spec['type'] && empty( $spec['values'] ) ) {
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
