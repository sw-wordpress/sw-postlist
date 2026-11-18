import { parseExpression } from './expression';
import { issue } from './position';
import { MAX_DEPTH, VOID_TAGS } from './profile';
import type { RawAttribute, Token } from './lexer';
import type { AttributeNode, Node, TemplateIssue } from './types';

interface Frame {
  kind: 'root' | 'element' | 'section';
  name: string;
  inverted: boolean;
  offset: number;
  attributes: AttributeNode[];
  children: Node[];
}

const frame = (kind: Frame['kind'], name: string, inverted: boolean, offset: number): Frame => ({
  kind,
  name,
  inverted,
  offset,
  attributes: [],
  children: [],
});

/** Mirrors Syntax/Parser.php: builds the tree and reports structural errors. */
export function parse(tokens: Token[], source: string): { nodes: Node[]; errors: TemplateIssue[] } {
  const errors: TemplateIssue[] = [];
  const stack: Frame[] = [frame('root', '', false, 0)];
  const top = (): Frame => stack[stack.length - 1] as Frame;
  const fail = (
    code: string,
    message: string,
    offset: number,
    context: Record<string, unknown> = {},
  ): void => {
    errors.push(issue(source, code, message, offset, context));
  };
  const append = (node: Node): void => {
    top().children.push(node);
  };

  const hasOpenSection = (name: string): boolean =>
    stack.some((entry) => entry.kind === 'section' && entry.name === name);

  const closeSection = (name: string, offset: number): void => {
    const current = top();
    if (current.kind !== 'section') {
      const message =
        current.kind === 'element'
          ? 'must be closed inside the same element'
          : 'does not match an open section';
      const code =
        current.kind === 'element' && hasOpenSection(name)
          ? 'section_crosses_element'
          : 'unexpected_section_end';
      fail(code, `Closing {{/${name}}} ${message}.`, offset, { name });
      return;
    }
    if (current.name !== name) {
      fail(
        'unexpected_section_end',
        `Closing {{/${name}}} does not match the open section {{#${current.name}}}.`,
        offset,
        { name },
      );
      return;
    }
    stack.pop();
    append({
      kind: 'section',
      name,
      inverted: current.inverted,
      children: current.children,
      offset: current.offset,
    });
  };

  const valueNodes = (value: NonNullable<RawAttribute['value']>): Node[] => {
    const result = parse(value, source);
    errors.push(...result.errors);
    return result.nodes;
  };

  for (const token of tokens) {
    if (token.kind === 'text') {
      append({ kind: 'text', text: token.value, offset: token.offset });
    } else if (token.kind === 'mustache') {
      const parsed = parseExpression(token.value, token.offset);
      if ('error' in parsed) {
        fail(parsed.code, parsed.error, token.offset);
        continue;
      }
      if (token.sigil === '') {
        append({ kind: 'variable', name: parsed.name, chain: parsed.chain, offset: token.offset });
        continue;
      }
      if (parsed.chain.length > 0) {
        fail('section_formatter', 'Section tags do not take formatters.', token.offset, {
          name: parsed.name,
        });
      }
      if (token.sigil !== '/') {
        stack.push(frame('section', parsed.name, token.sigil === '^', token.offset));
        continue;
      }
      closeSection(parsed.name, token.offset);
    } else if (token.kind === 'tag_open') {
      const attributes: AttributeNode[] = token.attributes.map((raw) => ({
        name: raw.name,
        value: raw.value === null ? null : valueNodes(raw.value),
        offset: raw.offset,
      }));
      const isVoid = VOID_TAGS.includes(token.value);
      if (token.selfClosing && !isVoid) {
        fail(
          'self_closing_element',
          `<${token.value} /> must be written with a closing tag.`,
          token.offset,
          { tag: token.value },
        );
        continue;
      }
      if (isVoid) {
        append({
          kind: 'element',
          tag: token.value,
          attributes,
          children: [],
          offset: token.offset,
        });
        continue;
      }
      const opened = frame('element', token.value, false, token.offset);
      opened.attributes = attributes;
      stack.push(opened);
      if (stack.length > MAX_DEPTH)
        fail('too_deep', 'Elements and sections are nested too deeply.', token.offset);
    } else {
      const name = token.value;
      if (VOID_TAGS.includes(name)) {
        fail('void_end_tag', `<${name}> must not have a closing tag.`, token.offset, { tag: name });
        continue;
      }
      const current = top();
      if (current.kind === 'section') {
        fail(
          'section_crosses_element',
          `Section {{#${current.name}}} must be closed inside the same element.`,
          token.offset,
          { name: current.name },
        );
        continue;
      }
      if (current.kind !== 'element' || current.name !== name) {
        fail(
          'mismatched_end_tag',
          `Closing </${name}> does not match the open element.`,
          token.offset,
          { tag: name },
        );
        continue;
      }
      stack.pop();
      append({
        kind: 'element',
        tag: name,
        attributes: current.attributes,
        children: current.children,
        offset: current.offset,
      });
    }
  }
  for (let open = stack.length; open > 1; open -= 1) {
    const unclosed = stack.pop() as Frame;
    if (unclosed.kind === 'element') {
      fail('unclosed_element', `Element <${unclosed.name}> is not closed.`, unclosed.offset, {
        tag: unclosed.name,
      });
    } else {
      fail('unclosed_section', `Section {{#${unclosed.name}}} is not closed.`, unclosed.offset, {
        name: unclosed.name,
      });
    }
  }
  return { nodes: (stack[0] as Frame).children, errors };
}
