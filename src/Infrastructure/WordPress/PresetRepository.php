<?php
/**
 * Presets from three places: the bundled JSON files in templates/presets, registrations through the
 * `sw_postlist_presets` filter and user presets. A user preset with the ID of another preset overrides it; deleting
 * the user preset resets to the original. Invalid documents are reported in the diagnostics and ignored.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Port\PresetStoreInterface;
use SW\Postlist\Application\Preset\PresetValidator;
use SW\Postlist\Domain\Preset\Preset;

final class PresetRepository implements PresetStoreInterface {

	public const OPTION = 'sw_postlist_user_presets';

	public const ORIGIN_BUNDLED   = 'bundled';
	public const ORIGIN_EXTENSION = 'extension';
	public const ORIGIN_USER      = 'user';

	/** @var list<array{code: string, message: string, source: string}> */
	private array $diagnostics = array();

	public function __construct( private readonly PresetValidator $validator, private readonly string $directory ) {
	}

	/**
	 * @return list<array{code: string, message: string, source: string}>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	/**
	 * Effective presets (user overrides applied) with their origin and whether a reset is possible.
	 *
	 * @return list<array{preset: Preset, origin: string, overrides: string|null}>
	 */
	public function all(): array {
		$this->diagnostics = array();
		$effective         = array();
		foreach ( $this->bundled() as $preset ) {
			$effective[ $preset->id ] = array(
				'preset'    => $preset,
				'origin'    => self::ORIGIN_BUNDLED,
				'overrides' => null,
			);
		}
		foreach ( $this->extensions() as $preset ) {
			$effective[ $preset->id ] = array(
				'preset'    => $preset,
				'origin'    => self::ORIGIN_EXTENSION,
				'overrides' => isset( $effective[ $preset->id ] ) ? $effective[ $preset->id ]['origin'] : null,
			);
		}
		foreach ( $this->user() as $preset ) {
			$effective[ $preset->id ] = array(
				'preset'    => $preset,
				'origin'    => self::ORIGIN_USER,
				'overrides' => isset( $effective[ $preset->id ] ) ? $effective[ $preset->id ]['origin'] : null,
			);
		}
		ksort( $effective );
		return array_values( $effective );
	}

	/**
	 * @return array{preset: Preset, origin: string, overrides: string|null}|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $entry ) {
			if ( $entry['preset']->id === $id ) {
				return $entry;
			}
		}
		return null;
	}

	public function export( string $id ): ?array {
		$entry = $this->find( $id );
		return null === $entry ? null : $entry['preset']->to_array();
	}

	public function has( string $id ): bool {
		return null !== $this->find( $id );
	}

	/** Saves (creates or overwrites) a user preset. */
	public function save_user( Preset $preset ): void {
		$stored                = $this->stored();
		$stored[ $preset->id ] = $preset->to_array();
		update_option( self::OPTION, $stored, false );
	}

	/** Removes a user preset or override; false when the user has none with this ID. */
	public function delete_user( string $id ): bool {
		$stored = $this->stored();
		if ( ! isset( $stored[ $id ] ) ) {
			return false;
		}
		unset( $stored[ $id ] );
		update_option( self::OPTION, $stored, false );
		return true;
	}

	/**
	 * @return list<Preset>
	 */
	private function bundled(): array {
		$presets = array();
		foreach ( glob( rtrim( $this->directory, '/' ) . '/*.json' ) ?: array() as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin file.
			$preset  = is_array( $decoded ) ? $this->accept( $decoded, basename( $file ) ) : null;
			if ( null === $preset ) {
				$this->diagnose( 'preset_file_invalid', 'The bundled preset file is not valid.', basename( $file ) );
				continue;
			}
			$presets[] = $preset;
		}
		return $presets;
	}

	/**
	 * @return list<Preset>
	 */
	private function extensions(): array {
		/**
		 * Registers presets (preset documents, see templates/presets/*.json). Invalid ones are reported in the
		 * diagnostics and ignored.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, array<string, mixed>> $presets Preset documents by ID.
		 */
		$registered = $this->as_array( apply_filters( 'sw_postlist_presets', array() ) );
		if ( null === $registered ) {
			$this->diagnose( 'presets_not_array', 'The sw_postlist_presets filter must return an array.', 'filter' );
			return array();
		}
		$presets = array();
		foreach ( $registered as $key => $document ) {
			$document = $this->as_array( $document );
			$preset   = null === $document ? null : $this->accept( $document, (string) $key );
			if ( null === $preset ) {
				$this->diagnose( 'preset_registration_invalid', 'A preset registered through sw_postlist_presets is not valid.', (string) $key );
				continue;
			}
			$presets[] = $preset;
		}
		return $presets;
	}

	/**
	 * @return list<Preset>
	 */
	private function user(): array {
		$presets = array();
		foreach ( $this->stored() as $id => $document ) {
			$preset = $this->accept( $document, (string) $id );
			if ( null === $preset ) {
				$this->diagnose( 'user_preset_invalid', 'A stored user preset is not valid.', (string) $id );
				continue;
			}
			$presets[] = $preset;
		}
		return $presets;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$clean  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $document ) {
			if ( is_string( $id ) && is_array( $document ) ) {
				$clean[ $id ] = $document;
			}
		}
		return $clean;
	}

	/**
	 * Filter results come from third parties: only arrays are used.
	 *
	 * @return array<mixed>|null
	 */
	private function as_array( mixed $value ): ?array {
		return is_array( $value ) ? $value : null;
	}

	/**
	 * @param array<mixed> $document Raw preset.
	 */
	private function accept( array $document, string $label ): ?Preset {
		unset( $label );
		$result = $this->validator->validate( $document );
		return $result->is_valid() ? $result->value() : null;
	}

	private function diagnose( string $code, string $message, string $source ): void {
		$this->diagnostics[] = array(
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}
}
