import { decode, normalize } from './entities';
import { validateDeclarations } from './css';
import { issue } from './position';
import { allowsAttribute, BOOLEAN_ATTRIBUTES, TARGETS, URL_ATTRIBUTES } from './profile';
import { CONTEXT_TYPES, typeOf } from './typer';
import { isSafeUrl } from './urlPolicy';
import type { TypeProblem } from './typer';
import type {
  AttributeNode,
  CompileContext,
  ElementNode,
  Node,
  SectionNode,
  TemplateIssue,
  ValueType,
  VariableNode,
} from './types';

const isStatic = (nodes: Node[]): boolean => nodes.every((node) => node.kind === 'text');
const staticText = (nodes: Node[]): string =>
  nodes.map((node) => (node.kind === 'text' ? node.text : '')).join('');

/** Mirrors Validator.php: profile, schema and formatter checks of a syntax tree. */
export function validate(nodes: Node[], context: CompileContext, source: string): TemplateIssue[] {
  const errors: TemplateIssue[] = [];
  const add = (
    code: string,
    message: string,
    offset: number,
    details: Record<string, unknown> = {},
  ): void => {
    errors.push(issue(source, code, message, offset, details));
  };
  const addAll = (problems: TypeProblem[]): void => {
    for (const problem of problems)
      add(problem.code, problem.message, problem.offset, problem.context);
  };

  const variable = (node: VariableNode, usage: string): ValueType | null => {
    const typed = typeOf(node, context);
    addAll(typed.errors);
    if (typed.type === null) return null;
    if (!(CONTEXT_TYPES[usage] ?? []).includes(typed.type)) {
      add(
        typed.type === 'html' ? 'html_only_in_text' : 'type_not_allowed_in_context',
        `A value of type ${typed.type} cannot be used in this position (${usage}).`,
        node.offset,
        { name: node.name, type: typed.type, usage },
      );
    }
    return typed.type;
  };

  const section = (node: SectionNode): void => {
    if (context.find(node.name, context.role) === null) {
      add('unknown_placeholder', `Unknown placeholder "${node.name}".`, node.offset, {
        name: node.name,
      });
    }
  };

  const attributeNodes = (list: Node[]): void => {
    for (const node of list) {
      if (node.kind === 'variable') variable(node, 'attr');
      else if (node.kind === 'section') {
        section(node);
        attributeNodes(node.children);
      }
    }
  };

  const urlAttribute = (name: string, list: Node[], offset: number): void => {
    if (isStatic(list)) {
      if (!isSafeUrl(decode(staticText(list)))) {
        add(
          'unsafe_url',
          `The URL in "${name}" is not allowed (http, https, mailto, tel or relative only).`,
          offset,
          { attribute: name },
        );
      }
      return;
    }
    const significant = list.filter((node) => !(node.kind === 'text' && node.text.trim() === ''));
    const only = significant[0];
    if (significant.length !== 1 || only === undefined || only.kind !== 'variable') {
      add(
        'url_attribute_mixed',
        `The attribute "${name}" takes a static URL or exactly one URL placeholder.`,
        offset,
        { attribute: name },
      );
      return;
    }
    variable(only, 'url');
  };

  const styleAttribute = (list: Node[], offset: number): void => {
    let css = '';
    for (const node of list) {
      if (node.kind === 'text') css += normalize(node.text);
      else if (node.kind === 'variable') {
        variable(node, 'style');
        css += '#000000';
      } else {
        add(
          'style_section',
          'Sections are not allowed in style attributes.',
          node.kind === 'section' ? node.offset : offset,
        );
      }
    }
    const reason = validateDeclarations(decode(css));
    if (reason !== null)
      add('invalid_style', `The style attribute contains forbidden content (${reason}).`, offset, {
        reason,
      });
  };

  const staticAttribute = (name: string, list: Node[], offset: number): void => {
    if (!isStatic(list)) {
      add('invalid_attribute_value', `The attribute "${name}" must be static.`, offset, {
        attribute: name,
      });
      return;
    }
    if (name === 'target' && !TARGETS.includes(staticText(list))) {
      add('invalid_attribute_value', 'Invalid target value.', offset, { attribute: name });
    }
  };

  const attribute = (tag: string, attr: AttributeNode): void => {
    const name = attr.name;
    if (name.startsWith('on')) {
      add('event_handler_attribute', 'Event handler attributes are not allowed.', attr.offset, {
        attribute: name,
      });
      return;
    }
    if (!allowsAttribute(context.profile, tag, name)) {
      add(
        'disallowed_attribute',
        `The attribute "${name}" is not allowed on <${tag}>.`,
        attr.offset,
        { attribute: name, tag },
      );
      return;
    }
    const value = attr.value;
    if (BOOLEAN_ATTRIBUTES.includes(name)) {
      if (value !== null && !isStatic(value))
        add('boolean_attribute_dynamic', `The attribute "${name}" must be static.`, attr.offset, {
          attribute: name,
        });
      return;
    }
    if (value === null) {
      add('invalid_attribute_value', `The attribute "${name}" needs a value.`, attr.offset, {
        attribute: name,
      });
      return;
    }
    if (URL_ATTRIBUTES.includes(name)) urlAttribute(name, value, attr.offset);
    else if (name === 'style') styleAttribute(value, attr.offset);
    else if (name === 'target' || name === 'rel') staticAttribute(name, value, attr.offset);
    else attributeNodes(value);
  };

  const walk = (list: Node[]): void => {
    for (const node of list) {
      if (node.kind === 'variable') variable(node, 'text');
      else if (node.kind === 'section') {
        section(node);
        walk(node.children);
      } else if (node.kind === 'element') element(node);
    }
  };

  const element = (node: ElementNode): void => {
    if (!context.profile.tags.includes(node.tag)) {
      add('disallowed_tag', `The element <${node.tag}> is not allowed.`, node.offset, {
        tag: node.tag,
      });
    } else {
      for (const attr of node.attributes) attribute(node.tag, attr);
    }
    walk(node.children);
  };

  walk(nodes);
  return errors;
}
