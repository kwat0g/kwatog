import '@testing-library/jest-dom';

// jsdom doesn't implement matchMedia; the theme store reads it on load.
// Stub it once so any module that triggers themeStore initialization
// during test imports doesn't blow up.
if (typeof window !== 'undefined' && !window.matchMedia) {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }),
  });
}

// jsdom doesn't implement scrollIntoView; any list that keeps its active row in
// view (command palette, keyboard-navigable menus) calls it from an effect.
if (typeof Element !== 'undefined' && !Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = () => {};
}

// jsdom doesn't implement ResizeObserver, and recharts' ResponsiveContainer
// constructs one in a mount effect — so ANY test that renders a real chart
// (SparkLine, DonutBreakdown, gauges) threw before its assertions ran. The stub
// never fires a callback, which is correct here: jsdom reports zero-size boxes,
// so a resize notification would carry no usable dimensions anyway.
if (typeof globalThis !== 'undefined' && !('ResizeObserver' in globalThis)) {
  class ResizeObserverStub {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  }

  Object.defineProperty(globalThis, 'ResizeObserver', {
    writable: true,
    value: ResizeObserverStub,
  });
}
