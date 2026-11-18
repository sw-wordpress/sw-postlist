import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, request } from '@/api/client';
import { toSignatures } from '@/api/convert';
import { api } from '@/api/endpoints';
import { stubApi } from './helpers';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('REST client', () => {
  it('sends the nonce, JSON and query parameters', async () => {
    const calls = stubApi({ 'POST /admin/views/validate': { json: { valid: true, errors: [] } } });
    const result = await request<{ valid: boolean }>(
      'POST',
      '/admin/views/validate',
      { a: 1 },
      { x: 'y' },
    );

    expect(result.valid).toBe(true);
    const fetchMock = vi.mocked(fetch);
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toContain('/wp-json/sw-postlist/v1/admin/views/validate?x=y');
    expect(init.credentials).toBe('same-origin');
    expect(init.headers).toMatchObject({
      'X-WP-Nonce': 'test-nonce',
      'Content-Type': 'application/json',
    });
    expect(calls[0]?.body).toEqual({ a: 1 });
  });

  it('omits the content type without a body', async () => {
    stubApi({ 'GET /admin/views': { json: { views: [] } } });
    await request('GET', '/admin/views');

    const [, init] = vi.mocked(fetch).mock.calls[0] as [string, RequestInit];
    expect(init.headers).not.toHaveProperty('Content-Type');
  });

  it('maps failures to ApiError with code, message and payload', async () => {
    stubApi({
      'POST /admin/views': {
        status: 422,
        json: { code: 'invalid_document', message: 'Nope', errors: [{ code: 'title_invalid' }] },
      },
    });

    const error = await request('POST', '/admin/views', {}).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: 422, code: 'invalid_document', message: 'Nope' });
    expect((error as ApiError).payload.errors).toEqual([{ code: 'title_invalid' }]);
  });

  it('copes with non-JSON error bodies', async () => {
    window.swpostlistConfig = {
      restRoot: 'https://site.test/wp-json/sw-postlist/v1/',
      nonce: 'n',
      locale: 'en_US',
      version: '1',
      adminUrl: '',
      siteUrl: '',
      pluginUrl: '',
    };
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('<html>', { status: 502 })),
    );

    const error = (await request('GET', '/x').catch((e: unknown) => e)) as ApiError;
    expect(error).toMatchObject({ status: 502, code: 'request_failed', message: 'HTTP 502' });
  });
});

describe('typed endpoints', () => {
  it('call the documented routes', async () => {
    const calls = stubApi({
      'GET /admin/views': { json: { views: [] } },
      'GET /admin/views/3': { json: { id: 3 } },
      'POST /admin/views': { json: {} },
      'PUT /admin/views/3': { json: {} },
      'DELETE /admin/views/3': { json: {} },
      'POST /admin/views/validate': { json: {} },
      'POST /admin/views/preview': { json: {} },
      'GET /admin/templates': { json: {} },
      'GET /admin/templates/4': { json: {} },
      'POST /admin/templates': { json: {} },
      'PUT /admin/templates/4': { json: {} },
      'DELETE /admin/templates/4': { json: {} },
      'POST /admin/templates/validate': { json: {} },
      'POST /admin/templates/preview': { json: {} },
      'GET /admin/sources': { json: {} },
      'GET /admin/placeholders': { json: {} },
      'GET /admin/diagnostics': { json: {} },
      'GET /admin/settings': { json: {} },
      'PUT /admin/settings': { json: {} },
    });
    const view = { schema_version: 1 } as never;
    const template = { schema_version: 1 } as never;

    await api.views.list();
    await api.views.get(3);
    await api.views.create(view, 'publish');
    await api.views.update(3, view, 'draft');
    await api.views.trash(3);
    await api.views.validate(view);
    await api.views.preview(view, 2, { entry: { html: 'x', css: '' } });
    await api.templates.list();
    await api.templates.get(4);
    await api.templates.create(template, 'post:post');
    await api.templates.update(4, template, 'post:post');
    await api.templates.trash(4);
    await api.templates.validate(template, 'post:post');
    await api.templates.preview(template, 'post:post');
    await api.system.sources();
    await api.system.placeholders('post:post', 'entry');
    await api.system.diagnostics();
    await api.system.settings();
    await api.system.saveSettings({ 'post:post': ['a'] });
    await api.system.saveSettings({}, { m: { hide: [], labels: {}, strip_prefix: '' } });

    expect(calls).toHaveLength(20);
    expect(calls.find((call) => call.path === '/admin/placeholders')?.query.get('source')).toBe(
      'post:post',
    );
    expect(calls.find((call) => call.path === '/admin/views/preview')?.body).toMatchObject({
      page: 2,
      entry_template: { html: 'x' },
    });
    expect(
      calls.find((call) => call.method === 'PUT' && call.path === '/admin/settings')?.body,
    ).toEqual({ meta_keys: { 'post:post': ['a'] } });
  });
});

describe('formatter signature conversion', () => {
  it('maps the server JSON to the helper types', () => {
    const converted = toSignatures({
      truncate: {
        inputs: ['text'],
        output: 'text',
        help: 'Cut',
        accepts_empty: false,
        params: [
          {
            name: 'length',
            type: 'int',
            required: true,
            min: 1,
            max: 100,
            allowed: null,
            max_length: null,
          },
        ],
      },
      default: {
        inputs: ['text'],
        output: 'text',
        accepts_empty: true,
        params: [
          { name: 'fallback', type: 'string', required: true, allowed: ['a'], max_length: 20 },
        ],
      },
    });

    expect(converted.truncate).toEqual({
      inputs: ['text'],
      output: 'text',
      help: 'Cut',
      acceptsEmpty: false,
      params: [{ name: 'length', type: 'int', required: true, min: 1, max: 100 }],
    });
    expect(converted.default).toMatchObject({ help: '', acceptsEmpty: true });
    expect(converted.default?.params[0]).toEqual({
      name: 'fallback',
      type: 'string',
      required: true,
      allowed: ['a'],
      maxLength: 20,
    });
  });
});
