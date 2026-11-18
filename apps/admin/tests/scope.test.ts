import postcss from 'postcss';
import { describe, expect, it } from 'vitest';
import { rewriteSelector, swpScope } from '../tools/postcssScope';

const r = (s: string): string => rewriteSelector(s, '.swp-root', 'swp-dark');

describe('rewriteSelector', () => {
  it('prefixes ordinary selectors', () => {
    expect(r('.q-btn')).toBe('.swp-root .q-btn');
    expect(r('*')).toBe('.swp-root *');
    expect(r('a:hover')).toBe('.swp-root a:hover');
  });
  it('maps html/body/:root to the root', () => {
    expect(r('html')).toBe('.swp-root');
    expect(r('body')).toBe('.swp-root');
    expect(r(':root')).toBe('.swp-root');
    expect(r('body.desktop .q-x')).toBe('.swp-root.desktop .q-x');
  });
  it('maps Quasar dark/light body classes onto our root classes', () => {
    expect(r('body.body--dark .q-card')).toBe('.swp-root.swp-dark .q-card');
    expect(r('.body--dark .q-card')).toBe('.swp-root.swp-dark .q-card');
    expect(r('body.body--light .q-card')).toBe('.swp-root:not(.swp-dark) .q-card');
  });
  it('neutralises body scroll-lock classes', () => {
    expect(r('body.q-body--prevent-scroll')).toBe('.swp-root.q-body--prevent-scroll');
  });
  it('is idempotent', () => {
    expect(r('.swp-root .q-btn')).toBe('.swp-root .q-btn');
  });
});

describe('swpScope plugin', () => {
  const run = (css: string, from: string): Promise<string> =>
    postcss([swpScope()])
      .process(css, { from })
      .then((res) => res.css);

  it('rewrites only Quasar files and leaves keyframes alone', async () => {
    const q = await run(
      '.a, body { x: 1 } @keyframes k { from { a: b } }',
      '/app/node_modules/quasar/dist/q.css',
    );
    expect(q).toContain('.swp-root .a');
    expect(q).toContain('.swp-root {');
    expect(q).toContain('from { a: b }');
    const own = await run('.swp-card { x: 1 }', '/app/src/styles/app.css');
    expect(own).toBe('.swp-card { x: 1 }');
  });
});
