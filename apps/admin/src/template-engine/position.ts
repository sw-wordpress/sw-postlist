import type { TemplateIssue } from './types';

/** 1-based line and column (in characters) of an offset. */
export function positionAt(source: string, offset: number): [number, number] {
  const clamped = Math.max(0, Math.min(offset, source.length));
  const before = source.slice(0, clamped);
  const line = before.split('\n').length;
  const last = before.lastIndexOf('\n');
  const tail = last === -1 ? before : before.slice(last + 1);
  return [line, [...tail].length + 1];
}

export function issue(
  source: string,
  code: string,
  message: string,
  offset: number,
  context: Record<string, unknown> = {},
): TemplateIssue {
  const [line, column] = positionAt(source, offset);
  return { code, message, line, column, context };
}
