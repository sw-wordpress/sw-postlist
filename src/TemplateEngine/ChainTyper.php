<?php
/**
 * Resolves a placeholder and type-checks its formatter chain; shared by the validator and the AST builder.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

use SW\Postlist\TemplateEngine\Node\VariableNode;

final class ChainTyper {

	/**
	 * Types a value of the given type may have in each output context.
	 */
	public const CONTEXT_TYPES = array(
		'text'  => array( 'text', 'number', 'date', 'url', 'color', 'html' ),
		'attr'  => array( 'text', 'number', 'date', 'url', 'color' ),
		'url'   => array( 'url', 'image' ),
		'style' => array( 'color' ),
	);

	/**
	 * @return array{type: ValueType|null, errors: list<array{code: string, message: string, offset: int, context: array<string, mixed>}>}
	 */
	public static function type_of( VariableNode $node, CompileContext $context ): array {
		$errors = array();
		$def    = $context->schema->find( $node->name, $context->role );
		if ( null === $def ) {
			$errors[] = self::error( 'unknown_placeholder', 'Unknown placeholder "' . $node->name . '".', $node->offset, array( 'name' => $node->name ) );
			return array(
				'type'   => null,
				'errors' => $errors,
			);
		}
		$type = $def->type;
		if ( array() !== $node->chain && ! $context->profile->formatters ) {
			$errors[] = self::error( 'formatters_disabled', 'Formatters are not available in this template profile.', $node->offset, array( 'name' => $node->name ) );
			return array(
				'type'   => null,
				'errors' => $errors,
			);
		}
		foreach ( $node->chain as $call ) {
			$formatter = $context->formatters->get( $call->name );
			if ( null === $formatter ) {
				$errors[] = self::error( 'unknown_formatter', 'Unknown formatter "' . $call->name . '".', $call->offset, array( 'formatter' => $call->name ) );
				return array(
					'type'   => null,
					'errors' => $errors,
				);
			}
			$signature = $formatter->signature();
			$problem   = self::parameter_problem( $call->name, $signature, $call->params, $call->offset );
			if ( null !== $problem ) {
				$errors[] = $problem;
				return array(
					'type'   => null,
					'errors' => $errors,
				);
			}
			if ( ! $signature->accepts( $type ) ) {
				$errors[] = self::error(
					'incompatible_formatter',
					'The formatter "' . $call->name . '" cannot be applied to a value of type ' . $type->value . '.',
					$call->offset,
					array(
						'formatter' => $call->name,
						'input'     => $type->value,
					)
				);
				return array(
					'type'   => null,
					'errors' => $errors,
				);
			}
			$type = $signature->output;
		}
		return array(
			'type'   => $type,
			'errors' => $errors,
		);
	}

	/**
	 * @param list<string|int|bool> $params Literals of the call.
	 * @return array{code: string, message: string, offset: int, context: array<string, mixed>}|null
	 */
	private static function parameter_problem( string $name, FormatterSignature $signature, array $params, int $offset ): ?array {
		if ( count( $params ) > count( $signature->params ) ) {
			return self::error( 'too_many_parameters', 'The formatter "' . $name . '" takes at most ' . count( $signature->params ) . ' parameter(s).', $offset, array( 'formatter' => $name ) );
		}
		foreach ( $signature->params as $index => $spec ) {
			if ( ! array_key_exists( $index, $params ) ) {
				if ( $spec->required ) {
					return self::error( 'missing_parameter', 'The formatter "' . $name . '" needs the parameter "' . $spec->name . '".', $offset, array( 'formatter' => $name ) );
				}
				continue;
			}
			$problem = $spec->problem( $params[ $index ] );
			if ( null !== $problem ) {
				return self::error( 'invalid_parameter', 'The parameter "' . $spec->name . '" of "' . $name . '" ' . $problem . '.', $offset, array( 'formatter' => $name ) );
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $context Details.
	 * @return array{code: string, message: string, offset: int, context: array<string, mixed>}
	 */
	private static function error( string $code, string $message, int $offset, array $context ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'offset'  => $offset,
			'context' => $context,
		);
	}
}
