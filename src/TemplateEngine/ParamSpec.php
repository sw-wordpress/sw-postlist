<?php
/**
 * Declaration of one literal formatter parameter.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class ParamSpec {

	public const TYPE_STRING = 'string';
	public const TYPE_INT    = 'int';
	public const TYPE_BOOL   = 'bool';

	/**
	 * @param string            $name     Name shown in the editor help.
	 * @param string            $type     One of the TYPE_* constants.
	 * @param bool              $required Whether the parameter must be given.
	 * @param int|null          $min      Lower bound of an int parameter.
	 * @param int|null          $max      Upper bound of an int parameter.
	 * @param list<string>|null $allowed Allowed values of a string parameter, null for any.
	 * @param int|null          $max_length Maximum length of a string parameter.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly bool $required = false,
		public readonly ?int $min = null,
		public readonly ?int $max = null,
		public readonly ?array $allowed = null,
		public readonly ?int $max_length = 200
	) {
	}

	/**
	 * Checks a literal; returns an error message or null.
	 */
	public function problem( mixed $value ): ?string {
		if ( self::TYPE_INT === $this->type ) {
			if ( ! is_int( $value ) ) {
				return 'expects a whole number';
			}
			if ( ( null !== $this->min && $value < $this->min ) || ( null !== $this->max && $value > $this->max ) ) {
				return 'is out of range';
			}
			return null;
		}
		if ( self::TYPE_BOOL === $this->type ) {
			return is_bool( $value ) ? null : 'expects true or false';
		}
		if ( ! is_string( $value ) ) {
			return 'expects a string';
		}
		if ( null !== $this->max_length && mb_strlen( $value ) > $this->max_length ) {
			return 'is too long';
		}
		if ( null !== $this->allowed && ! in_array( $value, $this->allowed, true ) ) {
			return 'has an unsupported value';
		}
		return null;
	}

	/**
	 * @return array{name: string, type: string, required: bool, min: int|null, max: int|null, allowed: list<string>|null, max_length: int|null}
	 */
	public function to_array(): array {
		return array(
			'name'       => $this->name,
			'type'       => $this->type,
			'required'   => $this->required,
			'min'        => $this->min,
			'max'        => $this->max,
			'allowed'    => $this->allowed,
			'max_length' => self::TYPE_STRING === $this->type ? $this->max_length : null,
		);
	}
}
