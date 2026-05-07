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
