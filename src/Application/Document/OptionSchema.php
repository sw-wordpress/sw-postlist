<?php
/**
 * Validation of one option value against its schema entry. Layouts and filter types declare their options with the same
 * vocabulary: `int`, `bool`, `string`, `enum`, `id_list`, `enum_list`, `device_int` and `device_bool`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Document;

use SW\Postlist\Domain\Validation\ValidationError;

final class OptionSchema {

	public const TYPES = array( 'int', 'bool', 'string', 'enum', 'id_list', 'enum_list', 'device_int', 'device_bool' );

	public const DEVICES = array( 'desktop', 'tablet', 'mobile' );

	/** Upper bound of list options, so a document cannot carry unbounded lists. */
	public const MAX_LIST = 50;

	/**
	 * @param array<string, mixed>  $spec   Schema entry of the option.
	 * @param mixed                 $value  Raw option value.
	 * @param string                $path   Error path.
	 * @param string                $code   Error code for invalid values.
	 * @param list<ValidationError> $errors Collected findings.
	 */
	public static function validate( array $spec, mixed $value, string $path, string $code, array &$errors ): void {
		$min = is_int( $spec['min'] ?? null ) ? $spec['min'] : PHP_INT_MIN;
		$max = is_int( $spec['max'] ?? null ) ? $spec['max'] : PHP_INT_MAX;
		switch ( $spec['type'] ?? null ) {
			case 'device_int':
				self::device_values( $value, $path, $min, $max, $code, $errors );
				return;
			case 'device_bool':
				self::device_flags( $value, $path, $code, $errors );
				return;
			case 'int':
				$valid = is_int( $value ) && $value >= $min && $value <= $max;
				break;
			case 'bool':
				$valid = is_bool( $value );
				break;
			case 'enum':
				$valid = is_string( $value ) && in_array( $value, self::values( $spec ), true );
				break;
			case 'id_list':
				$valid = self::is_id_list( $value );
				break;
			case 'enum_list':
				$valid = self::is_enum_list( $value, self::values( $spec ) );
				break;
			default:
				$valid = ( null === $value && ! empty( $spec['null'] ) ) || ( is_string( $value ) && ( ! is_string( $spec['pattern'] ?? null ) || 1 === preg_match( $spec['pattern'], $value ) ) );
		}
		if ( ! $valid ) {
			$errors[] = new ValidationError( $code, $path, 'The option is invalid.' );
		}
	}

	/**
	 * @param array<string, mixed> $spec Schema entry.
	 * @return list<mixed>
	 */
	private static function values( array $spec ): array {
		return is_array( $spec['values'] ?? null ) ? array_values( $spec['values'] ) : array();
	}

	private static function is_id_list( mixed $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_LIST ) {
			return false;
		}
		foreach ( $value as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param list<mixed> $allowed Allowed values.
	 */
	private static function is_enum_list( mixed $value, array $allowed ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_LIST || count( array_unique( $value, SORT_REGULAR ) ) !== count( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || ! in_array( $item, $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param list<ValidationError> $errors Collected findings.
	 */
	private static function device_values( mixed $value, string $path, int $min, int $max, string $code, array &$errors ): void {
		if ( ! is_array( $value ) || array() !== array_diff( array_keys( $value ), self::DEVICES ) ) {
			$errors[] = new ValidationError( $code, $path, 'Expected values for desktop, tablet and mobile.' );
			return;
		}
		foreach ( $value as $device => $number ) {
			if ( ! is_int( $number ) || $number < $min || $number > $max ) {
				$errors[] = new ValidationError( $code, $path . '.' . $device, sprintf( 'Expected an integer between %d and %d.', $min, $max ) );
			}
		}
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param list<ValidationError> $errors Collected findings.
	 */
	private static function device_flags( mixed $value, string $path, string $code, array &$errors ): void {
		if ( ! is_array( $value ) || array() !== array_diff( array_keys( $value ), self::DEVICES ) ) {
			$errors[] = new ValidationError( $code, $path, 'Expected flags for desktop, tablet and mobile.' );
			return;
		}
		foreach ( $value as $device => $flag ) {
			if ( ! is_bool( $flag ) ) {
				$errors[] = new ValidationError( $code, $path . '.' . $device, 'Expected true or false.' );
			}
		}
	}
}
