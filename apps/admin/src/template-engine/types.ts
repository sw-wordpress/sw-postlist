/**
 * Public types of the template engine helpers (PLAN.md §5). This folder depends on nothing outside itself, so it can
 * become an npm package later (M10). It mirrors the PHP engine in src/TemplateEngine and is verified against the same
 * contract fixtures (tests/contract/template-engine).
 *
 * @since 0.1.0
 */

export const VALUE_TYPES = [
  'text',
  'number',
  'date',
  'list',
  'url',
  'html',
  'flag',
  'color',
  'image',
] as const;
export type ValueType = (typeof VALUE_TYPES)[number];

export interface PlaceholderDefinition {
  name: string;
  type: ValueType;
  description: string;
  example: string;
  /** Template roles that may use the placeholder; empty means every role. */
  roles: string[];
}

export interface ParamSpec {
  name: string;
  type: 'string' | 'int' | 'bool';
  required: boolean;
  min?: number;
  max?: number;
  allowed?: string[];
  maxLength?: number;
}

export interface FormatterSignature {
  inputs: ValueType[];
  output: ValueType;
  params: ParamSpec[];
  help: string;
  acceptsEmpty: boolean;
}

export interface EngineProfile {
  tags: string[];
  globalAttributes: string[];
  tagAttributes: Record<string, string[]>;
  classPrefix: string;
  formatters: boolean;
}

export interface TemplateIssue {
  code: string;
  message: string;
  line: number;
  column: number;
  context: Record<string, unknown>;
}

export type Literal = string | number | boolean;

export interface FormatterCall {
  name: string;
  params: Literal[];
  offset: number;
}

export type Node = TextNode | VariableNode | SectionNode | ElementNode;
export interface TextNode {
  kind: 'text';
  text: string;
  offset: number;
}
export interface VariableNode {
  kind: 'variable';
  name: string;
  chain: FormatterCall[];
  offset: number;
}
export interface SectionNode {
  kind: 'section';
  name: string;
  inverted: boolean;
  children: Node[];
  offset: number;
}
export interface AttributeNode {
  name: string;
  value: Node[] | null;
  offset: number;
}
export interface ElementNode {
  kind: 'element';
  tag: string;
  attributes: AttributeNode[];
  children: Node[];
  offset: number;
}

export type Usage = 'text' | 'attr' | 'style';
export type AstChain = Array<[string, Literal[]]>;
export type AstNode =
  | ['t', string]
  | ['v', string, Usage, AstChain, ValueType]
  | ['u', string, string, AstChain, ValueType]
  | ['s', string, boolean, AstNode[]];

export interface CompiledTemplate {
  ast: AstNode[];
  css: string;
  scopeClass: string;
}

export interface CompileResult {
  template: CompiledTemplate | null;
  errors: TemplateIssue[];
}

/** Host supplied catalogue: placeholders per role and the available formatters. */
export interface CompileContext {
  profile: EngineProfile;
  find: (name: string, role: string) => PlaceholderDefinition | null;
  formatters: Record<string, FormatterSignature>;
  role: string;
}
