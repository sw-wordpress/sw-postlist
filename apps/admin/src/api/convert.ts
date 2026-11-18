import type { FormatterSignature, ParamSpec, ValueType } from '@/template-engine';

interface ServerParam {
  name: string;
  type: ParamSpec['type'];
  required: boolean;
  min?: number | null;
  max?: number | null;
  allowed?: string[] | null;
  max_length?: number | null;
}

interface ServerSignature {
  inputs: ValueType[];
  output: ValueType;
  params: ServerParam[];
  help?: string;
  accepts_empty?: boolean;
}

/** Server formatter signatures (snake_case JSON) as the engine helpers expect them. */
export function toSignatures(
  formatters: Record<string, unknown>,
): Record<string, FormatterSignature> {
  const signatures: Record<string, FormatterSignature> = {};
  for (const [name, raw] of Object.entries(formatters)) {
    const server = raw as ServerSignature;
    signatures[name] = {
      inputs: server.inputs,
      output: server.output,
      help: server.help ?? '',
      acceptsEmpty: server.accepts_empty ?? false,
      params: server.params.map((param) => ({
        name: param.name,
        type: param.type,
        required: param.required,
        ...(param.min != null ? { min: param.min } : {}),
        ...(param.max != null ? { max: param.max } : {}),
        ...(param.allowed != null ? { allowed: param.allowed } : {}),
        ...(param.max_length != null ? { maxLength: param.max_length } : {}),
      })),
    };
  }
  return signatures;
}
