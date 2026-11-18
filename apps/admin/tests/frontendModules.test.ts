import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

/** The runtime modules of the masonry and slider layouts (assets/js, M6): progressive enhancement of rendered markup. */
interface Module {
  init: (view: Element) => void;
  initAll: (root?: ParentNode) => void;
}
type Registry = { masonry: Module; slider: Module };
const registry = (): Registry => (window as unknown as { SWPostlist: Registry }).SWPostlist;

beforeAll(async () => {
  await import('../../../assets/js/masonry.js');
  await import('../../../assets/js/slider.js');
});

afterEach(() => {
  document.body.innerHTML = '';
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

const sliderMarkup = (options: Record<string, unknown> = {}, slides = 3): string =>
  `<div class="swp-view" data-swp-module="slider" data-swp-options='${JSON.stringify(options)}'>
    <div class="swp-slider"><div class="swp-slider__track" tabindex="0">
      ${Array.from({ length: slides }, (_, index) => `<div class="swp-item">Slide ${index}</div>`).join('')}
    </div></div></div>`;

const mountSlider = (options: Record<string, unknown> = {}, slides = 3) => {
  document.body.innerHTML = sliderMarkup(options, slides);
  registry().slider.initAll(document);
  const view = document.querySelector('.swp-view') as HTMLElement;
  const track = view.querySelector('.swp-slider__track') as HTMLElement;
  const scrollTo = vi.fn();
  track.scrollTo = scrollTo as unknown as typeof track.scrollTo;
  return { view, track, scrollTo };
};

const click = (element: Element | null): void => {
  (element as HTMLElement).click();
};

describe('slider module', () => {
  it('adds ARIA roles, arrows and dots and moves between slides', () => {
    const { view, track, scrollTo } = mountSlider();
    const slides = Array.from(track.children);
    expect(slides[1]?.getAttribute('aria-roledescription')).toBe('slide');
    expect(slides[1]?.getAttribute('aria-label')).toBe('2 / 3');
    const prev = view.querySelector<HTMLButtonElement>('.swp-slider__prev');
    const next = view.querySelector<HTMLButtonElement>('.swp-slider__next');
    const dots = view.querySelectorAll('.swp-slider__dot');
    expect(dots).toHaveLength(3);
    expect(prev?.disabled).toBe(true);
    expect(dots[0]?.getAttribute('aria-current')).toBe('true');

    click(next);
    expect(scrollTo).toHaveBeenCalledTimes(1);
    expect(dots[1]?.getAttribute('aria-current')).toBe('true');
    expect(prev?.disabled).toBe(false);
    click(dots[2] ?? null);
    expect(next?.disabled).toBe(true);
    click(next);
    expect(dots[2]?.getAttribute('aria-current')).toBe('true');
    click(prev);
    expect(dots[1]?.getAttribute('aria-current')).toBe('true');
  });

  it('loops around when configured', () => {
    const { view } = mountSlider({ loop: true });
    const prev = view.querySelector<HTMLButtonElement>('.swp-slider__prev');
    const next = view.querySelector<HTMLButtonElement>('.swp-slider__next');
    const dots = view.querySelectorAll('.swp-slider__dot');
    expect(prev?.disabled).toBe(false);
    click(prev);
    expect(dots[2]?.getAttribute('aria-current')).toBe('true');
    click(next);
    expect(dots[0]?.getAttribute('aria-current')).toBe('true');
  });

  it('supports the keyboard and prevents the default of handled keys only', () => {
    const { view, track } = mountSlider();
    const dots = view.querySelectorAll('.swp-slider__dot');
    const press = (key: string): boolean =>
      track.dispatchEvent(new KeyboardEvent('keydown', { key, cancelable: true, bubbles: true }));
    expect(press('ArrowRight')).toBe(false);
    expect(dots[1]?.getAttribute('aria-current')).toBe('true');
    press('ArrowLeft');
    expect(dots[0]?.getAttribute('aria-current')).toBe('true');
    press('End');
    expect(dots[2]?.getAttribute('aria-current')).toBe('true');
    press('Home');
    expect(dots[0]?.getAttribute('aria-current')).toBe('true');
    expect(press('a')).toBe(true);
  });

  it('can hide arrows and dots and ignores invalid or out of range options', () => {
    const { view } = mountSlider({ arrows: false, dots: false });
    expect(view.querySelector('.swp-slider__controls')).toBeNull();

    document.body.innerHTML = sliderMarkup({ interval: 'soon', autoplay: 'yes' }).replace(
      /data-swp-options='[^']*'/,
      "data-swp-options='{broken'",
    );
    registry().slider.initAll(document);
    expect(document.querySelectorAll('.swp-slider__dot')).toHaveLength(3);
    expect(document.querySelector('.swp-slider__pause')).toBeNull();
  });

  it('initialises a slider only once and ignores markup without a track', () => {
    const { view } = mountSlider();
    registry().slider.init(view);
    expect(view.querySelectorAll('.swp-slider__controls')).toHaveLength(1);
    const empty = document.createElement('div');
    expect(() => registry().slider.init(empty)).not.toThrow();
  });

  it('follows swipes and scrolling by updating the current slide', () => {
    vi.useFakeTimers();
    const { view, track } = mountSlider();
    const slides = Array.from(track.children) as HTMLElement[];
    slides.forEach((slide, index) =>
      Object.defineProperty(slide, 'offsetLeft', { value: index * 100 }),
    );
    track.scrollLeft = 190;
    track.dispatchEvent(new Event('scroll'));
    track.dispatchEvent(new Event('scroll'));
    vi.advanceTimersByTime(150);
    expect(view.querySelectorAll('.swp-slider__dot')[2]?.getAttribute('aria-current')).toBe('true');
  });

  it('autoplays, pauses on hover, focus and the pause button and stops on hidden tabs', () => {
    vi.useFakeTimers();
    const { view, track, scrollTo } = mountSlider({ autoplay: true, interval: 3000, loop: true });
    const pause = view.querySelector<HTMLButtonElement>('.swp-slider__pause');
    expect(pause).not.toBeNull();
    expect(track.getAttribute('aria-live')).toBe('off');
    vi.advanceTimersByTime(3000);
    expect(scrollTo).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(6000);
    expect(scrollTo).toHaveBeenCalledTimes(3);

    view.dispatchEvent(new Event('mouseenter'));
    expect(track.getAttribute('aria-live')).toBe('polite');
    vi.advanceTimersByTime(9000);
    expect(scrollTo).toHaveBeenCalledTimes(3);
    view.dispatchEvent(new Event('mouseleave'));
    view.dispatchEvent(new Event('focusin'));
    vi.advanceTimersByTime(9000);
    expect(scrollTo).toHaveBeenCalledTimes(3);
    view.dispatchEvent(new Event('focusout'));
    vi.advanceTimersByTime(3000);
    expect(scrollTo).toHaveBeenCalledTimes(4);

    click(pause);
    expect(pause?.getAttribute('aria-pressed')).toBe('true');
    vi.advanceTimersByTime(9000);
    expect(scrollTo).toHaveBeenCalledTimes(4);
    click(pause);
    expect(pause?.getAttribute('aria-pressed')).toBe('false');

    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
    vi.advanceTimersByTime(9000);
    expect(scrollTo).toHaveBeenCalledTimes(4);
    Object.defineProperty(document, 'hidden', { configurable: true, value: false });
    document.dispatchEvent(new Event('visibilitychange'));
  });

  it('does not autoplay with reduced motion', () => {
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    vi.useFakeTimers();
    const { view, scrollTo } = mountSlider({ autoplay: true, interval: 2000 });
    expect(view.querySelector('.swp-slider__pause')).toBeNull();
    vi.advanceTimersByTime(10000);
    expect(scrollTo).not.toHaveBeenCalled();
    click(view.querySelector('.swp-slider__next'));
    expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ behavior: 'auto' }));
  });
});

describe('masonry module', () => {
  const mountMasonry = () => {
    document.body.innerHTML = `<div class="swp-view" data-swp-module="masonry">
      <div class="swp-masonry" style="column-gap: 10px">
        <div class="swp-item"><article>One</article></div>
        <div class="swp-item">Two</div>
      </div></div>`;
    const grid = document.querySelector('.swp-masonry') as HTMLElement;
    const items = Array.from(grid.children) as HTMLElement[];
    (items[0]?.firstElementChild as HTMLElement).getBoundingClientRect = () =>
      ({ height: 120.2 }) as DOMRect;
    items[1]!.getBoundingClientRect = () => ({ height: 0 }) as DOMRect;
    return { grid, items };
  };

  const flushFrames = (): void => {
    vi.advanceTimersByTime(50);
  };

  it('spans rows by content height and marks the grid ready', () => {
    vi.useFakeTimers();
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
      window.setTimeout(() => callback(0), 0),
    );
    const { grid, items } = mountMasonry();
    registry().masonry.initAll(document);
    flushFrames();
    expect(grid.classList.contains('is-ready')).toBe(true);
    expect(items[0]?.style.gridRowEnd).toBe('span 131');
    expect(items[1]?.style.gridRowEnd).toBe('span 10');
  });

  it('re-lays out when an image loads and initialises only once', () => {
    vi.useFakeTimers();
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
      window.setTimeout(() => callback(0), 0),
    );
    const { grid, items } = mountMasonry();
    registry().masonry.init(document.querySelector('.swp-view') as Element);
    flushFrames();
    (items[0]?.firstElementChild as HTMLElement).getBoundingClientRect = () =>
      ({ height: 200 }) as DOMRect;
    grid.dispatchEvent(new Event('load'));
    grid.dispatchEvent(new Event('load'));
    flushFrames();
    expect(items[0]?.style.gridRowEnd).toBe('span 210');
    registry().masonry.init(document.querySelector('.swp-view') as Element);
    expect(grid.getAttribute('data-swp-ready')).toBe('1');
  });

  it('falls back to the window resize event without ResizeObserver and ignores foreign markup', () => {
    vi.useFakeTimers();
    vi.stubGlobal('ResizeObserver', undefined);
    Reflect.deleteProperty(window, 'ResizeObserver');
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
      window.setTimeout(() => callback(0), 0),
    );
    const { grid, items } = mountMasonry();
    registry().masonry.initAll(document);
    flushFrames();
    (items[0]?.firstElementChild as HTMLElement).getBoundingClientRect = () =>
      ({ height: 50 }) as DOMRect;
    window.dispatchEvent(new Event('resize'));
    flushFrames();
    expect(items[0]?.style.gridRowEnd).toBe('span 60');
    expect(grid.classList.contains('is-ready')).toBe(true);
    expect(() => registry().masonry.init(document.createElement('div'))).not.toThrow();
  });
});
