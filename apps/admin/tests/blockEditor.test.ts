import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

interface Element {
  type: unknown;
  props: Record<string, unknown>;
  children: unknown[];
}

type Edit = (props: {
  attributes: Record<string, unknown>;
  setAttributes: (value: Record<string, unknown>) => void;
}) => Element;

interface Registered {
  edit: Edit;
  save: () => unknown;
}

const apiFetch = vi.fn();
let registered: Record<string, Registered> = {};
let hooksState: { views: unknown; effects: Array<() => void> };

function setupWp(): void {
  hooksState = { views: null, effects: [] };
  const wp = {
    blocks: {
      registerBlockType: (name: string, settings: Registered): void => {
        registered[name] = settings;
      },
    },
    element: {
      createElement: (
        type: unknown,
        props: Record<string, unknown> | null,
        ...children: unknown[]
      ): Element => ({
        type,
        props: props ?? {},
        children,
      }),
      Fragment: 'Fragment',
      useEffect: (callback: () => void): void => {
        hooksState.effects.push(callback);
      },
      useState: (initial: unknown): [unknown, (value: unknown) => void] => [
        hooksState.views ?? initial,
        (value: unknown): void => {
          hooksState.views = value;
        },
      ],
    },
    blockEditor: {
      InspectorControls: 'InspectorControls',
      useBlockProps: () => ({ className: 'wp-block' }),
    },
    components: {
      PanelBody: 'PanelBody',
      SelectControl: 'SelectControl',
      TextControl: 'TextControl',
      Placeholder: 'Placeholder',
      Spinner: 'Spinner',
    },
    i18n: { __: (text: string): string => text },
    apiFetch,
    serverSideRender: 'ServerSideRender',
  };
  (window as unknown as { wp: unknown }).wp = wp;
}

const find = (node: unknown, type: string): Element[] => {
  const out: Element[] = [];
  const visit = (value: unknown): void => {
    if (Array.isArray(value)) value.forEach(visit);
    else if (value !== null && typeof value === 'object') {
      const element = value as Element;
      if (element.type === type) out.push(element);
      visit(element.children);
    }
  };
  visit(node);
  return out;
};

describe('Postlist block editor script', () => {
  beforeEach(async () => {
    registered = {};
    apiFetch.mockReset();
    setupWp();
    vi.resetModules();
    await import('../../../blocks/view/index.js');
  });

  afterEach(() => {
    delete (window as unknown as { wp?: unknown }).wp;
  });

  it('registers a dynamic block without a saved markup', () => {
    const block = registered['sw-postlist/view'];
    expect(block).toBeDefined();
    expect(block?.save()).toBeNull();
  });

  it('loads the list of views and shows a spinner meanwhile', async () => {
    apiFetch.mockResolvedValue([{ id: 4, title: 'News' }]);
    const edit = registered['sw-postlist/view']?.edit as Edit;

    const loading = edit({ attributes: { viewId: 0 }, setAttributes: vi.fn() });
    expect(find(loading, 'Spinner')).toHaveLength(1);
    expect(hooksState.effects).toHaveLength(1);

    hooksState.effects[0]?.();
    await Promise.resolve();
    await Promise.resolve();
    expect(apiFetch).toHaveBeenCalledWith({ path: '/sw-postlist/v1/views' });
    expect(hooksState.views).toEqual([{ id: 4, title: 'News' }]);
  });

  it('falls back to an empty list when loading fails', async () => {
    apiFetch.mockRejectedValue(new Error('offline'));
    const edit = registered['sw-postlist/view']?.edit as Edit;
    edit({ attributes: { viewId: 0 }, setAttributes: vi.fn() });

    hooksState.effects[0]?.();
    await Promise.resolve();
    await Promise.resolve();
    expect(hooksState.views).toEqual([]);
  });

  it('asks for a view when none is selected and offers the views as options', () => {
    hooksState.views = [{ id: 4, title: 'News' }];
    const edit = registered['sw-postlist/view']?.edit as Edit;
    const result = edit({ attributes: { viewId: 0 }, setAttributes: vi.fn() });

    expect(find(result, 'Placeholder')).toHaveLength(1);
    const select = find(result, 'SelectControl')[0];
    expect(select?.props.options).toEqual([
      { value: 0, label: 'Select a view' },
      { value: 4, label: 'News' },
    ]);
  });

  it('renders the server preview for a selected view and writes attribute changes', () => {
    hooksState.views = [{ id: 4, title: 'News' }];
    const setAttributes = vi.fn();
    const edit = registered['sw-postlist/view']?.edit as Edit;
    const attributes = { viewId: 4, instanceKey: '', layout: '', perPage: '', columns: '' };
    const result = edit({ attributes, setAttributes });

    const preview = find(result, 'ServerSideRender')[0];
    expect(preview?.props).toMatchObject({ block: 'sw-postlist/view', attributes });

    const [viewSelect, layoutSelect] = find(result, 'SelectControl');
    (viewSelect?.props.onChange as (value: string) => void)('7');
    expect(setAttributes).toHaveBeenLastCalledWith({ viewId: 7 });
    (viewSelect?.props.onChange as (value: string) => void)('junk');
    expect(setAttributes).toHaveBeenLastCalledWith({ viewId: 0 });
    (layoutSelect?.props.onChange as (value: string) => void)('list');
    expect(setAttributes).toHaveBeenLastCalledWith({ layout: 'list' });

    const [keyField, perPageField] = find(result, 'TextControl');
    (keyField?.props.onChange as (value: string) => void)('news');
    expect(setAttributes).toHaveBeenLastCalledWith({ instanceKey: 'news' });
    (perPageField?.props.onChange as (value: string) => void)('6');
    expect(setAttributes).toHaveBeenLastCalledWith({ perPage: '6' });
  });
});
