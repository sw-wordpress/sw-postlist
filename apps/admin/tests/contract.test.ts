import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = resolve(import.meta.dirname, '../../../tests/contract');

describe('shared contract fixtures', () => {
  const directories = readdirSync(root, { withFileTypes: true }).filter((entry) =>
    entry.isDirectory(),
  );

  it('finds fixtures', () => {
    expect(directories.length).toBeGreaterThan(0);
  });

  it.each(directories.map((directory) => directory.name))(
    'every fixture in %s is well formed',
    (directory) => {
      const names = readdirSync(join(root, directory)).filter((name) => name.endsWith('.json'));
      expect(names.length).toBeGreaterThan(0);
      for (const name of names) {
        const fixture = JSON.parse(readFileSync(join(root, directory, name), 'utf8')) as Record<
          string,
          unknown
        >;
        for (const key of ['name', 'type', 'input', 'expect']) {
          expect(fixture, `${name} lacks ${key}`).toHaveProperty(key);
        }
        expect(fixture.type).toBe(directory);
      }
    },
  );
});
