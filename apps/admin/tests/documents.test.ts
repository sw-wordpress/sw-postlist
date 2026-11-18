import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import Ajv2020 from 'ajv/dist/2020';
import { describe, expect, it } from 'vitest';
import { validateTemplateDocument, validateViewDocument } from '@/documents/validate';

const root = resolve(import.meta.dirname, '../../..');

interface Fixture {
  name: string;
  input: Record<string, unknown>;
  expect: { valid?: boolean; errors?: string[] };
}

function load(type: string): Fixture[] {
  const dir = join(root, 'tests/contract', type);
  return readdirSync(dir)
    .filter((name) => name.endsWith('.json'))
    .map((name) => JSON.parse(readFileSync(join(dir, name), 'utf8')) as Fixture);
}

const schemaOf = (name: string): object =>
  JSON.parse(readFileSync(join(root, 'schemas', name), 'utf8')) as object;

describe.each([
  ['view-document', validateViewDocument, 'view-document.v1.schema.json'],
  ['template-document', validateTemplateDocument, 'template-document.v1.schema.json'],
] as const)('%s contract fixtures', (type, validate, schemaFile) => {
  const ajv = new Ajv2020({ strict: false, allErrors: true });
  const schema = ajv.compile(schemaOf(schemaFile));

  it.each(load(type).map((fixture) => [fixture.name, fixture] as const))(
    '%s matches the expected outcome',
    (_name, fixture) => {
      const issues = validate(fixture.input);
      if (fixture.expect.valid) {
        expect(issues).toEqual([]);
        expect(schema(fixture.input), JSON.stringify(schema.errors)).toBe(true);
        return;
      }
      expect([...new Set(issues.map((issue) => issue.code))]).toEqual(fixture.expect.errors);
    },
  );
});
