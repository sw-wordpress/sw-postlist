import type {
  CompileContext,
  FormatterCall,
  FormatterSignature,
  Literal,
  ParamSpec,
  ValueType,
  VariableNode,
} from './types';

/** Types a value may have in each output context (ChainTyper.php). */
export const CONTEXT_TYPES: Record<string, string[]> = {
  text: ['text', 'number', 'date', 'url', 'color', 'html'],
  attr: ['text', 'number', 'date', 'url', 'color'],
  url: ['url', 'image'],
  style: ['color'],
};

export interface TypeProblem {
  code: string;
  message: string;
  offset: number;
  context: Record<string, unknown>;
}

export function paramProblem(spec: ParamSpec, value: Literal): string | null {
  if (spec.type === 'int') {
    if (typeof value !== 'number' || !Number.isInteger(value)) return 'expects a whole number';
    if (
      (spec.min !== undefined && value < spec.min) ||
      (spec.max !== undefined && value > spec.max)
    ) {
      return 'is out of range';
    }
    return null;
  }
  if (spec.type === 'bool') return typeof value === 'boolean' ? null : 'expects true or false';
  if (typeof value !== 'string') return 'expects a string';
  if (spec.maxLength !== undefined && [...value].length > spec.maxLength) return 'is too long';
  if (spec.allowed !== undefined && !spec.allowed.includes(value))
    return 'has an unsupported value';
  return null;
}

function parameterProblem(
  name: string,
  signature: FormatterSignature,
  call: FormatterCall,
): TypeProblem | null {
  const params = call.params;
  if (params.length > signature.params.length) {
    return {
      code: 'too_many_parameters',
      message: `The formatter "${name}" takes at most ${signature.params.length} parameter(s).`,
      offset: call.offset,
      context: { formatter: name },
    };
  }
  for (const [index, spec] of signature.params.entries()) {
    const value = params[index];
    if (value === undefined) {
      if (spec.required) {
        return {
          code: 'missing_parameter',
          message: `The formatter "${name}" needs the parameter "${spec.name}".`,
          offset: call.offset,
          context: { formatter: name },
        };
      }
      continue;
    }
    const problem = paramProblem(spec, value);
    if (problem !== null) {
      return {
        code: 'invalid_parameter',
        message: `The parameter "${spec.name}" of "${name}" ${problem}.`,
        offset: call.offset,
        context: { formatter: name },
      };
    }
  }
  return null;
}

/** Resolves a placeholder and type-checks its formatter chain. */
export function typeOf(
  node: VariableNode,
  context: CompileContext,
): { type: ValueType | null; errors: TypeProblem[] } {
  const definition = context.find(node.name, context.role);
  if (definition === null) {
    return {
      type: null,
      errors: [
        {
          code: 'unknown_placeholder',
          message: `Unknown placeholder "${node.name}".`,
          offset: node.offset,
          context: { name: node.name },
        },
      ],
    };
  }
  let type: ValueType = definition.type;
  if (node.chain.length > 0 && !context.profile.formatters) {
    return {
      type: null,
      errors: [
        {
          code: 'formatters_disabled',
          message: 'Formatters are not available in this template profile.',
          offset: node.offset,
          context: { name: node.name },
        },
      ],
    };
  }
  for (const call of node.chain) {
    const signature = context.formatters[call.name];
    if (signature === undefined) {
      return {
        type: null,
        errors: [
          {
            code: 'unknown_formatter',
            message: `Unknown formatter "${call.name}".`,
            offset: call.offset,
            context: { formatter: call.name },
          },
        ],
      };
    }
    const problem = parameterProblem(call.name, signature, call);
    if (problem !== null) return { type: null, errors: [problem] };
    if (!signature.inputs.includes(type)) {
      return {
        type: null,
        errors: [
          {
            code: 'incompatible_formatter',
            message: `The formatter "${call.name}" cannot be applied to a value of type ${type}.`,
            offset: call.offset,
            context: { formatter: call.name, input: type },
          },
        ],
      };
    }
    type = signature.output;
  }
  return { type, errors: [] };
}
