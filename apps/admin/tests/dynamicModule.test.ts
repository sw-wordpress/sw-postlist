import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/** The module of dynamic views (assets/js/dynamic.js, M7): filters, AJAX pagination, load more, infinite scroll. */
interface Module {
  init: (view: Element) => void;
  initAll: (root?: ParentNode) => void;
}
const registry = (): { dynamic: Module; masonry?: { init: () => void; refresh: () => void } } =>
  (window as unknown as { SWPostlist: { dynamic: Module } }).SWPostlist as never;

const REST = 'https://site.test/wp-json/sw-postlist/v1/';

beforeAll(async () => {
  (window as unknown as { SWPostlist: unknown }).SWPostlist = {
    rest: REST,
    i18n: { loading: 'Loading…', retry: 'Try again', more: 'Load more' },
  };
  await import('../../../assets/js/dynamic.js');
});

interface Call {
  url: URL;
  aborted: boolean;
}
let calls: Call[];
let reply: (call: Call) => Promise<Response>;

const page = (items: string[], next: string | null, extra = ''): string =>
  `<div class="swp-view" data-swp-view="7" data-swp-instance="view-7-1" data-swp-mode="ajax">
    ${extra}
    <div class="swp-grid">${items.map((item) => `<div class="swp-item">${item}</div>`).join('')}</div>
    ${next === null ? '' : `<nav class="swp-pagination"><ul><li><a class="swp-pagination__link swp-pagination__link--next" rel="next" href="${next}">Next</a></li></ul></nav>`}
  </div>`;

const form = (value = ''): string => `<form class="swp-filters" action="#swp-view-7-1">
  <input type="hidden" name="page_id" value="42">
  <input type="hidden" name="swp[view-7-2][f][q]" value="other">
  <fieldset class="swp-filter swp-filter--buttons">
    <button type="submit" class="swp-filter__button is-active" aria-pressed="true" name="swp[view-7-1][f][tax_category]" value="">All</button>
    <button type="submit" class="swp-filter__button" aria-pressed="false" name="swp[view-7-1][f][tax_category]" value="3">Cat</button>
  </fieldset>
  <select name="swp[view-7-1][f][author]"><option value="">All</option><option value="2">Ann</option></select>
  <input type="search" name="swp[view-7-1][f][q]" value="${value}">
  <div class="swp-filters__actions"><button type="submit" class="swp-filters__apply">Apply</button><a class="swp-filters__reset" href="?x#a">Reset</a></div>
</form>`;

const json = (html: string, extra: Record<string, unknown> = {}, status = 200): Promise<Response> =>
  Promise.resolve(
    new Response(JSON.stringify({ html, page: 1, error: null, ...extra }), { status }),
  );

const mount = (html: string, token = 'signed.token'): HTMLElement => {
  document.body.innerHTML = html.replace(
    'data-swp-mode',
    `data-swp-token="${token}" data-swp-mode`,
  );
  const view = document.querySelector('.swp-view') as HTMLElement;
  registry().dynamic.init(view);
  return view;
};

const settle = async (): Promise<void> => {
  await vi.advanceTimersByTimeAsync(600);
};

const titles = (view: Element): string[] =>
  Array.from(view.querySelectorAll('.swp-item')).map((item) => item.textContent ?? '');

beforeEach(() => {
  vi.useFakeTimers();
  calls = [];
  reply = () => json(page(['New'], null));
  vi.stubGlobal('fetch', (url: string, init: { signal: AbortSignal }) => {
    const call: Call = { url: new URL(url), aborted: false };
    init.signal.addEventListener('abort', () => {
      call.aborted = true;
    });
    calls.push(call);
    return reply(call);
  });
  window.history.replaceState(null, '', '/archive/?page_id=42');
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

describe('dynamic views: filters', () => {
  it('reloads through the public route with the form state and keeps the url in sync', async () => {
    window.history.replaceState(
      null,
      '',
      '/archive/?page_id=42&swp%5Bview-7-2%5D%5Bf%5D%5Bq%5D=other',
    );
    const view = mount(page(['Old'], null, form()));
    reply = () => json(page(['Found A', 'Found B'], null, form()), { page: 1 });

    (view.querySelector('select') as HTMLSelectElement).value = '2';
    view.querySelector('select')?.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();

    expect(calls).toHaveLength(1);
    expect(calls[0]?.url.pathname).toBe('/wp-json/sw-postlist/v1/views/7/render');
    expect(calls[0]?.url.searchParams.get('key')).toBe('view-7-1');
    expect(calls[0]?.url.searchParams.get('token')).toBe('signed.token');
    const query = new URLSearchParams(calls[0]?.url.searchParams.get('query') ?? '');
    expect(query.get('swp[view-7-1][f][author]')).toBe('2');
    expect(query.get('page_id')).toBe('42');
    expect(query.get('swp[view-7-2][f][q]')).toBe('other');
    expect(query.has('swp[view-7-1][f][q]')).toBe(false);
    expect(titles(view)).toEqual(['Found A', 'Found B']);
    expect(window.location.search).toContain('author%5D=2');
    expect(view.getAttribute('aria-busy')).toBeNull();
    expect(document.querySelector('.swp-status')?.textContent).toBe('2 entries shown.');
  });

  it('submits a clicked filter button as the new value and marks the live form', async () => {
    const view = mount(page(['Old'], null, form()));
    reply = () => json(page(['Cat entry'], null, form()));
    expect(view.querySelector('.swp-filters')?.classList.contains('swp-filters--live')).toBe(true);

    (view.querySelectorAll('.swp-filter__button')[1] as HTMLButtonElement).click();
    await settle();

    const query = new URLSearchParams(calls[0]?.url.searchParams.get('query') ?? '');
    expect(query.get('swp[view-7-1][f][tax_category]')).toBe('3');
    expect(titles(view)).toEqual(['Cat entry']);
  });

  it('debounces typing in the search field and ignores empty values in the url', async () => {
    const view = mount(page(['Old'], null, form()));
    const input = view.querySelector('input[type="search"]') as HTMLInputElement;
    for (const value of ['b', 'ba', 'ban']) {
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      await vi.advanceTimersByTimeAsync(100);
    }
    expect(calls).toHaveLength(0);
    await settle();
    expect(calls).toHaveLength(1);
    expect(
      new URLSearchParams(calls[0]?.url.searchParams.get('query') ?? '').get('swp[view-7-1][f][q]'),
    ).toBe('ban');
    expect(window.location.search).not.toContain('author');
  });

  it('keeps the focused filter form while the results are replaced', async () => {
    const view = mount(page(['Old'], null, form()));
    const input = view.querySelector('input[type="search"]') as HTMLInputElement;
    input.focus();
    reply = () => json(page(['Result'], null, form('typed')));
    input.value = 'typed';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();

    expect(view.querySelector('input[type="search"]')).toBe(input);
    expect(document.activeElement).toBe(input);
    expect(titles(view)).toEqual(['Result']);
  });

  it('refreshes the selection marks of a focused button group and its reset link', async () => {
    const view = mount(page(['Old'], null, form()));
    const buttons = view.querySelectorAll<HTMLButtonElement>('.swp-filter__button');
    reply = () =>
      json(
        page(
          ['Cat'],
          null,
          form()
            .replace(
              'class="swp-filter__button is-active" aria-pressed="true"',
              'class="swp-filter__button" aria-pressed="false"',
            )
            .replace(
              'aria-pressed="false" name="swp[view-7-1][f][tax_category]" value="3"',
              'aria-pressed="true" name="swp[view-7-1][f][tax_category]" value="3"',
            )
            .replace(
              'class="swp-filter__button" aria-pressed="true"',
              'class="swp-filter__button is-active" aria-pressed="true"',
            )
            .replace(
              '<a class="swp-filters__reset" href="?x#a">Reset</a>',
              '<a class="swp-filters__reset" href="?changed#a">Reset</a>',
            ),
        ),
      );
    buttons[1]?.focus();
    buttons[1]?.click();
    await settle();

    expect(view.querySelectorAll('.swp-filter__button')[1]).toBe(buttons[1]);
    expect(buttons[1]?.classList.contains('is-active')).toBe(true);
    expect(buttons[0]?.classList.contains('is-active')).toBe(false);
    expect(buttons[1]?.getAttribute('aria-pressed')).toBe('true');
    expect(view.querySelector('.swp-filters__reset')?.getAttribute('href')).toBe('?changed#a');
  });

  it('adds and removes the reset link when the server state changes', async () => {
    const view = mount(page(['Old'], null, form()));
    const select = view.querySelector('select') as HTMLSelectElement;
    select.focus();
    reply = () =>
      json(
        page(
          ['X'],
          null,
          form().replace('<a class="swp-filters__reset" href="?x#a">Reset</a>', ''),
        ),
      );
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(view.querySelector('.swp-filters__reset')).toBeNull();

    reply = () => json(page(['Y'], null, form()));
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(view.querySelector('.swp-filters__reset')?.getAttribute('href')).toBe('?x#a');
  });
});

describe('dynamic views: pagination', () => {
  it('turns page links into reloads in AJAX mode and moves focus to the list', async () => {
    const view = mount(page(['One'], '?page_id=42&swp%5Bview-7-1%5D%5Bpage%5D=2'));
    reply = () => json(page(['Two'], null), { page: 2 });
    (view.querySelector('.swp-pagination__link') as HTMLAnchorElement).click();
    await settle();

    const query = new URLSearchParams(calls[0]?.url.searchParams.get('query') ?? '');
    expect(query.get('swp[view-7-1][page]')).toBe('2');
    expect(titles(view)).toEqual(['Two']);
    expect(window.location.search).toContain('page%5D=2');
    expect(document.activeElement).toBe(view);
    expect(document.querySelector('.swp-status')?.textContent).toBe('Page 2 loaded.');
  });

  it('leaves modified clicks and non-AJAX modes to the browser', async () => {
    const view = mount(page(['One'], '?swp%5Bview-7-1%5D%5Bpage%5D=2'));
    const link = view.querySelector('.swp-pagination__link') as HTMLAnchorElement;
    link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));
    link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 1 }));
    expect(calls).toHaveLength(0);

    const classic = mount(
      page(['One'], '?swp%5Bview-7-1%5D%5Bpage%5D=2').replace(
        'data-swp-mode="ajax"',
        'data-swp-mode="classic"',
      ),
    );
    const prevented = !(
      classic.querySelector('.swp-pagination__link') as HTMLAnchorElement
    ).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(prevented).toBe(false);
    expect(calls).toHaveLength(0);
  });

  it('restores the state from the url on browser back and forward', async () => {
    mount(page(['One'], '?swp%5Bview-7-1%5D%5Bpage%5D=2'));
    window.history.replaceState(null, '', '/archive/?page_id=42&swp%5Bview-7-1%5D%5Bpage%5D=3');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await settle();

    const last = calls.at(-1);
    expect(
      new URLSearchParams(last?.url.searchParams.get('query') ?? '').get('swp[view-7-1][page]'),
    ).toBe('3');
    expect(window.location.search).toContain('page%5D=3');
  });
});

describe('dynamic views: several instances', () => {
  it('builds the new url from the address bar so that stale links and fields cannot reset other instances', async () => {
    window.history.replaceState(
      null,
      '',
      '/archive/?page_id=42&swp%5Bview-7-2%5D%5Bf%5D%5Bq%5D=fresh&swp%5Bview-7-1%5D%5Bpage%5D=9',
    );
    const view = mount(
      page(
        ['One'],
        '?page_id=42&swp%5Bview-7-2%5D%5Bf%5D%5Bq%5D=stale&swp%5Bview-7-1%5D%5Bpage%5D=2',
      ),
    );
    reply = () => json(page(['Two'], null), { page: 2 });
    (view.querySelector('.swp-pagination__link') as HTMLAnchorElement).click();
    await settle();

    const query = new URLSearchParams(calls[0]?.url.searchParams.get('query') ?? '');
    expect(query.get('swp[view-7-2][f][q]')).toBe('fresh');
    expect(query.get('swp[view-7-1][page]')).toBe('2');
    expect(query.get('page_id')).toBe('42');
    expect(window.location.search).toContain('fresh');
    expect(window.location.search).not.toContain('stale');
  });

  it('keeps other instances when a filter form of this instance submits', async () => {
    window.history.replaceState(null, '', '/archive/?swp%5Bview-7-2%5D%5Bf%5D%5Bq%5D=fresh');
    const view = mount(page(['One'], null, form()));
    reply = () => json(page(['Found'], null, form()));
    (view.querySelector('select') as HTMLSelectElement).value = '2';
    view.querySelector('select')?.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    const query = new URLSearchParams(calls[0]?.url.searchParams.get('query') ?? '');
    expect(query.get('swp[view-7-2][f][q]')).toBe('fresh');
    expect(query.get('swp[view-7-1][f][author]')).toBe('2');
    expect(query.has('page_id')).toBe(false);
  });

  it('announces a single entry in the singular', async () => {
    const view = mount(page(['Old'], null, form()));
    reply = () => json(page(['Only'], null, form()));
    view.querySelector('select')?.dispatchEvent(new Event('change', { bubbles: true }));
    await settle();
    expect(document.querySelector('.swp-status')?.textContent).toBe('1 entry shown.');
  });
});

describe('dynamic views: load more and infinite scroll', () => {
  const loadMoreMarkup = (mode: string): string =>
    page(['One', 'Two'], '?swp%5Bview-7-1%5D%5Bpage%5D=2').replace(
      'data-swp-mode="ajax"',
      `data-swp-mode="${mode}"`,
    );

  it('replaces the page links by a button that appends the next page until the end', async () => {
    const view = mount(loadMoreMarkup('load_more'));
    const nav = view.querySelector('.swp-pagination') as HTMLElement;
    expect(nav.hidden).toBe(true);
    const button = view.querySelector('.swp-load-more') as HTMLButtonElement;
    expect(button.textContent).toBe('Load more');

    reply = () => json(page(['Three'], '?swp%5Bview-7-1%5D%5Bpage%5D=3'), { page: 2 });
    button.click();
    await settle();
    expect(titles(view)).toEqual(['One', 'Two', 'Three']);
    expect(
      new URLSearchParams(calls[0]?.url.searchParams.get('query') ?? '').get('swp[view-7-1][page]'),
    ).toBe('2');
    expect(window.location.search).toBe('?page_id=42');
    expect(view.querySelector('.swp-load-more')).not.toBeNull();

    reply = () => json(page(['Four'], null), { page: 3 });
    (view.querySelector('.swp-load-more') as HTMLButtonElement).click();
    await settle();
    expect(titles(view)).toEqual(['One', 'Two', 'Three', 'Four']);
    expect(view.querySelector('.swp-load-more')).toBeNull();
    expect(document.querySelector('.swp-status')?.textContent).toBe('4 entries shown.');
  });

  it('does not start a second request while one is running and refreshes masonry after appending', async () => {
    const refresh = vi.fn();
    (window as unknown as { SWPostlist: Record<string, unknown> }).SWPostlist.masonry = {
      init: vi.fn(),
      refresh,
    };
    const view = mount(loadMoreMarkup('load_more'));
    let release: (response: Response) => void = () => undefined;
    reply = () =>
      new Promise<Response>((resolve) => {
        release = resolve;
      });
    const button = view.querySelector('.swp-load-more') as HTMLButtonElement;
    button.click();
    button.click();
    expect(calls).toHaveLength(1);
    release(new Response(JSON.stringify({ html: page(['Three'], null), page: 2, error: null })));
    await settle();
    expect(refresh).toHaveBeenCalledTimes(1);
    delete (window as unknown as { SWPostlist: Record<string, unknown> }).SWPostlist.masonry;
  });

  it('loads the next page when the button scrolls into view in infinite mode', async () => {
    const observers: Array<{
      callback: (entries: Array<{ isIntersecting: boolean }>) => void;
      observe: () => void;
      disconnect: () => void;
    }> = [];
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        callback: (entries: Array<{ isIntersecting: boolean }>) => void;
        constructor(callback: (entries: Array<{ isIntersecting: boolean }>) => void) {
          this.callback = callback;
          observers.push(this as never);
        }
        observe(): void {}
        disconnect(): void {}
      },
    );
    const view = mount(loadMoreMarkup('infinite'));
    expect(observers).toHaveLength(1);
    reply = () => json(page(['Three'], null), { page: 2 });
    observers[0]?.callback([{ isIntersecting: false }]);
    expect(calls).toHaveLength(0);
    observers[0]?.callback([{ isIntersecting: true }]);
    await settle();
    expect(titles(view)).toEqual(['One', 'Two', 'Three']);
  });

  it('replaces the content when the list was empty before appending', async () => {
    const view = mount(
      page([], '?swp%5Bview-7-1%5D%5Bpage%5D=2').replace(
        'data-swp-mode="ajax"',
        'data-swp-mode="load_more"',
      ),
    );
    reply = () => json(page(['Late'], null), { page: 2 });
    (view.querySelector('.swp-load-more') as HTMLButtonElement).click();
    await settle();
    expect(titles(view)).toEqual(['Late']);
  });
});

describe('dynamic views: errors and robustness', () => {
  it('shows an error notice with a retry that repeats the request and then recovers', async () => {
    const view = mount(page(['Old'], null, form()));
    reply = () => Promise.reject(new TypeError('offline'));
    (view.querySelector('select') as HTMLSelectElement).dispatchEvent(
      new Event('change', { bubbles: true }),
    );
    await settle();

    const notice = view.querySelector('.swp-notice');
    expect(notice?.getAttribute('role')).toBe('alert');
    expect(titles(view)).toEqual(['Old']);
    expect(view.getAttribute('aria-busy')).toBeNull();

    reply = () => json(page(['Recovered'], null, form()));
    (view.querySelector('.swp-retry') as HTMLButtonElement).click();
    await settle();
    expect(view.querySelector('.swp-notice')).toBeNull();
    expect(titles(view)).toEqual(['Recovered']);
  });

  it('treats server errors and malformed answers as failures and keeps the old content', async () => {
    const view = mount(page(['Old'], null, form()));
    for (const answer of [
      () => json('', { error: 'view_not_found' }, 404),
      () => json('<p>no wrapper</p>'),
      () => Promise.resolve(new Response('not json', { status: 200 })),
      () => Promise.resolve(new Response(JSON.stringify({ html: 5 }), { status: 200 })),
    ]) {
      reply = answer;
      (view.querySelector('select') as HTMLSelectElement).dispatchEvent(
        new Event('change', { bubbles: true }),
      );
      await settle();
      expect(view.querySelector('.swp-notice')).not.toBeNull();
      expect(titles(view)).toEqual(['Old']);
    }
  });

  it('aborts the running request when a newer one starts and ignores its answer', async () => {
    const view = mount(page(['Old'], null, form()));
    const resolvers: Array<(response: Response) => void> = [];
    reply = () =>
      new Promise<Response>((resolve) => {
        resolvers.push(resolve);
      });
    const select = view.querySelector('select') as HTMLSelectElement;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.value = '2';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    expect(calls[0]?.aborted).toBe(true);
    resolvers[0]?.(
      new Response(JSON.stringify({ html: page(['Stale'], null, form()), page: 1, error: null })),
    );
    resolvers[1]?.(
      new Response(JSON.stringify({ html: page(['Fresh'], null, form()), page: 1, error: null })),
    );
    await settle();
    expect(titles(view)).toEqual(['Fresh']);
  });

  it('works without a token, initialises once and ignores views without a reload route', async () => {
    const view = mount(page(['Old'], null, form()), '');
    registry().dynamic.init(view);
    expect(document.querySelectorAll('.swp-status')).toHaveLength(1);
    (view.querySelector('select') as HTMLSelectElement).dispatchEvent(
      new Event('change', { bubbles: true }),
    );
    await settle();
    expect(calls[0]?.url.searchParams.has('token')).toBe(false);

    const config = (window as unknown as { SWPostlist: { rest?: string } }).SWPostlist;
    const rest = config.rest;
    delete config.rest;
    document.body.innerHTML = page(['x'], null);
    const plain = document.querySelector('.swp-view') as HTMLElement;
    registry().dynamic.initAll(document);
    expect(plain.getAttribute('data-swp-dynamic')).toBeNull();
    config.rest = rest;
  });

  it('announces an empty result', async () => {
    const view = mount(page(['Old'], null, form()));
    reply = () => json(page([], null, form()));
    (view.querySelector('select') as HTMLSelectElement).dispatchEvent(
      new Event('change', { bubbles: true }),
    );
    await settle();
    expect(document.querySelector('.swp-status')?.textContent).toBe('No entries found.');
  });
});
