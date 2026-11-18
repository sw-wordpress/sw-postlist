<?php
/**
 * Typing of a formatter: accepted input types, output type and literal parameters.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class FormatterSignature {

	/**
	 * @param list<ValueType> $inputs Accepted input types.
	 * @param ValueType       $output Produced type.
	 * @param list<ParamSpec> $params Literal parameters in call order.
	 * @param string          $help   One line for the editor help.
	 * @param bool            $accepts_empty Whether the formatter is also applied to empty (null) values.
	 */
	public function __construct(
		public readonly array $inputs,
		public readonly ValueType $output,
		public readonly array $params = array(),
		public readonly string $help = '',
		public readonly bool $accepts_empty = false
	) {
	}

	public function accepts( ValueType $type ): bool {
		return in_array( $type, $this->inputs, true );
	}

	/**
	 * @return array{inputs: list<string>, output: string, params: list<array<string, mixed>>, accepts_empty: bool}
	 */
	public function to_array(): array {
		return array(
			'inputs'        => array_map( static fn ( ValueType $type ): string => $type->value, $this->inputs ),
			'output'        => $this->output->value,
			'params'        => array_map( static fn ( ParamSpec $param ): array => $param->to_array(), $this->params ),
			'accepts_empty' => $this->accepts_empty,
		);
	}

	/**
	 * Signature plus the help text, for editor screens (the contract file compares {@see to_array()} only).
	 *
	 * @return array<string, mixed>
	 */
	public function to_editor_array(): array {
		return $this->to_array() + array( 'help' => $this->help );
	}
}
