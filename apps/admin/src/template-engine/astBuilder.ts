import { decode, normalize } from './entities';
import { BOOLEAN_ATTRIBUTES, URL_ATTRIBUTES, VOID_TAGS } from './profile';
import { typeOf } from './typer';
import type {
  AstChain,
  AstNode,
  AttributeNode,
  CompileContext,
  ElementNode,
  Node,
  Usage,
  ValueType,
  VariableNode,
} from './types';

const allStatic = (nodes: Node[]): boolean => nodes.every((node) => node.kind === 'text');

function merge(nodes: AstNode[]): AstNode[] {
  const out: AstNode[] = [];
  for (const node of nodes) {
    const last = out[out.length - 1];
    if (node[0] === 't' && last !== undefined && last[0] === 't') {
      out[out.length - 1] = ['t', last[1] + node[1]];
      continue;
    }
    out.push(node);
  }
  return out;
}

/** Mirrors AstBuilder.php: static markup merged into strings, placeholders and sections stay dynamic. */
export function buildAst(nodes: Node[], context: CompileContext): AstNode[] {
  const chainOf = (node: VariableNode): AstChain =>
    node.chain.map((call) => [call.name, call.params]);
  const typeName = (node: VariableNode, fallback: ValueType): ValueType =>
    typeOf(node, context).type ?? fallback;

  const staticValue = (attr: AttributeNode): string | null =>
    attr.value === null
      ? null
      : decode(attr.value.map((node) => (node.kind === 'text' ? node.text : '')).join('')).trim();

  const attribute = (attr: AttributeNode): AstNode[] => {
    const { name, value } = attr;
    if (value === null || BOOLEAN_ATTRIBUTES.includes(name)) return [['t', ` ${name}`]];
    if (URL_ATTRIBUTES.includes(name) && !allStatic(value)) {
      for (const node of value) {
        if (node.kind === 'variable') {
          return [['u', name, node.name, chainOf(node), typeName(node, 'url')]];
        }
      }
    }
    const parts = list(value, name === 'style' ? 'style' : 'attr');
    if (allStatic(value)) {
      const text = parts.map((part) => (part[0] === 't' ? part[1] : '')).join('');
      return [['t', ` ${name}="${text}"`]];
    }
    return [['t', ` ${name}="`], ...parts, ['t', '"']];
  };

  const element = (node: ElementNode): AstNode[] => {
    const out: AstNode[] = [['t', `<${node.tag}`]];
    let target: string | null = null;
    let rel: string | null = null;
    for (const attr of node.attributes) {
      if (attr.name === 'target') target = staticValue(attr);
      else if (attr.name === 'rel') rel = staticValue(attr);
      else out.push(...attribute(attr));
    }
    if (target !== null) {
      out.push(['t', ` target="${target}"`]);
      if (target === '_blank') {
        const words = (rel ?? '')
          .trim()
          .toLowerCase()
          .split(/\s+/)
          .filter((word) => word !== '');
        rel = [...new Set([...words, 'noopener', 'noreferrer'])].join(' ');
      }
    }
    if (rel !== null && rel !== '') out.push(['t', ` rel="${normalize(rel)}"`]);
    out.push(['t', '>']);
    if (!VOID_TAGS.includes(node.tag)) {
      out.push(...list(node.children, 'text'), ['t', `</${node.tag}>`]);
    }
    return out;
  };

  const list = (items: Node[], usage: Usage): AstNode[] => {
    const out: AstNode[] = [];
    for (const node of items) {
      if (node.kind === 'text') out.push(['t', normalize(node.text)]);
      else if (node.kind === 'variable') {
        out.push(['v', node.name, usage, chainOf(node), typeName(node, 'text')]);
      } else if (node.kind === 'section') {
        out.push(['s', node.name, node.inverted, merge(list(node.children, usage))]);
      } else out.push(...element(node));
    }
    return out;
  };

  return merge(list(nodes, 'text'));
}
