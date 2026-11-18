import { buildAst } from './astBuilder';
import { scopeCss } from './css';
import { tokenize } from './lexer';
import { parse } from './parser';
import { MAX_CSS_BYTES, MAX_TEMPLATE_BYTES, scopeClass } from './profile';
import { validate } from './validator';
import type { CompileContext, CompileResult, TemplateIssue } from './types';

const fatal = (code: string, message: string): TemplateIssue => ({
  code,
  message,
  line: 1,
  column: 1,
  context: {},
});

/** Compiles template HTML and CSS: lexer, parser, validator, AST builder and CSS scoper (Compiler.php). */
export function compileTemplate(
  template: string,
  css: string,
  context: CompileContext,
  templateId: number | string,
): CompileResult {
  const encoder = new TextEncoder();
  if (encoder.encode(template).length > MAX_TEMPLATE_BYTES) {
    return { template: null, errors: [fatal('too_long', 'The template is too long.')] };
  }
  const lexed = tokenize(template);
  const parsed = parse(lexed.tokens, lexed.source);
  let errors = [...lexed.errors, ...parsed.errors];
  if (errors.length === 0) errors = validate(parsed.nodes, context, lexed.source);
  const scope = scopeClass(context.profile, templateId);
  let scoped = '';
  try {
    scoped = css.trim() === '' ? '' : scopeCss(css, `.${scope}`);
  } catch (error) {
    const reason = error instanceof Error ? error.message : 'invalid';
    errors.push({
      code: 'invalid_css',
      message: `The CSS is not acceptable: ${reason}.`,
      line: 1,
      column: 1,
      context: { reason },
    });
  }
  if (errors.length > 0) return { template: null, errors };
  return {
    template: { ast: buildAst(parsed.nodes, context), css: scoped, scopeClass: scope },
    errors: [],
  };
}

export { MAX_CSS_BYTES };
