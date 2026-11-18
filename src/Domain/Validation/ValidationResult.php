<?php
/**
 * Outcome of a validation: either a normalized value or a list of errors.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Validation;

/**
 * @template T
 */
final class ValidationResult {

	/**
	 * @param array{0: T}|null      $box    Holds the normalized value on success, null when errors exist.
	 * @param list<ValidationError> $errors Findings, empty on success.
	 */
	private function __construct(
		private readonly ?array $box,
		private readonly array $errors
	) {
	}

	/**
	 * @template V
	 * @param V $value Normalized value.
	 * @return self<V>
	 */
	public static function valid( mixed $value ): self {
		return new self( array( $value ), array() );
	}

	/**
	 * @param list<ValidationError> $errors Findings, at least one.
	 * @return self<never>
	 */
	public static function invalid( array $errors ): self {
		return new self( null, $errors );
	}

	public function is_valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * @return T
	 */
	public function value(): mixed {
		if ( null === $this->box ) {
			throw new \LogicException( 'A validation result with errors has no value.' );
		}
		return $this->box[0];
	}

	/**
	 * @return list<ValidationError>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * @return list<string>
	 */
	public function error_codes(): array {
		return array_map( static fn ( ValidationError $error ): string => $error->code, $this->errors );
	}
}
