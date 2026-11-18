<?php
/**
 * Registry of formatters keyed by name. Invalid registrations are collected for the diagnostics.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Formatter;

use SW\Postlist\TemplateEngine\Port\FormatterInterface;
use SW\Postlist\TemplateEngine\Port\FormatterRegistryInterface;

final class FormatterRegistry implements FormatterRegistryInterface {

	/** @var array<string, FormatterInterface> */
	private array $formatters = array();

	/** @var list<array{code: string, message: string, source: string}> */
	private array $diagnostics = array();

	/**
	 * @param mixed $candidates Formatters (untrusted: may come from a filter).
	 */
	public function __construct( mixed $candidates = array() ) {
		if ( ! is_array( $candidates ) ) {
			$this->diagnose( 'formatters_not_array', 'The formatter list must be an array.', gettype( $candidates ) );
			return;
		}
		foreach ( $candidates as $candidate ) {
			$this->register( $candidate );
		}
	}

	public function get( string $name ): ?FormatterInterface {
		return $this->formatters[ $name ] ?? null;
	}

	public function all(): array {
		return $this->formatters;
	}

	/**
	 * @return list<array{code: string, message: string, source: string}>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	public function fingerprint(): string {
		$signatures = array();
		foreach ( $this->formatters as $name => $formatter ) {
			$signatures[ $name ] = $formatter->signature()->to_array();
		}
		ksort( $signatures );
		return hash( 'sha256', (string) json_encode( $signatures ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free engine.
	}

	private function register( mixed $candidate ): void {
		if ( ! $candidate instanceof FormatterInterface ) {
			$this->diagnose( 'formatter_interface', 'A formatter must implement FormatterInterface.', is_object( $candidate ) ? get_class( $candidate ) : gettype( $candidate ) );
			return;
		}
		$label = get_class( $candidate );
		try {
			$name      = $candidate->name();
			$signature = $candidate->signature();
		} catch ( \Throwable $error ) {
			$this->diagnose( 'formatter_failed', 'The formatter threw while it was registered: ' . $error->getMessage(), $label );
			return;
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
			$this->diagnose( 'formatter_name_invalid', 'Invalid formatter name "' . $name . '".', $label );
			return;
		}
		if ( isset( $this->formatters[ $name ] ) ) {
			$this->diagnose( 'formatter_duplicate', 'Duplicate formatter "' . $name . '"; the first registration is kept.', $label );
			return;
		}
		if ( array() === $signature->inputs ) {
			$this->diagnose( 'formatter_signature_invalid', 'The formatter "' . $name . '" accepts no input type.', $label );
			return;
		}
		$this->formatters[ $name ] = $candidate;
	}

	private function diagnose( string $code, string $message, string $source ): void {
		$this->diagnostics[] = array(
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}
}
