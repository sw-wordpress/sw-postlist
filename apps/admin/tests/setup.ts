// jsdom lacks the layout APIs CodeMirror measures with; the editor tests only need them not to throw.
const emptyRect = {
  x: 0,
  y: 0,
  width: 0,
  height: 0,
  top: 0,
  left: 0,
  right: 0,
  bottom: 0,
  toJSON: () => ({}),
};
Range.prototype.getClientRects = () =>
  ({
    length: 0,
    item: () => null,
    [Symbol.iterator]: [][Symbol.iterator],
  }) as unknown as DOMRectList;
Range.prototype.getBoundingClientRect = () => emptyRect as DOMRect;

// Quasar's tabs observe their size.
class ResizeObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = ResizeObserverStub;

// Quasar scrolls tab strips and lists.
Element.prototype.scrollTo = (() => undefined) as unknown as typeof Element.prototype.scrollTo;
