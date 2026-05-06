import '@testing-library/jest-dom';

/**
 * jsdom does not implement matchMedia. The themeStore — which is loaded
 * transitively by anything that touches authStore — calls
 * window.matchMedia('(prefers-color-scheme: dark)'). Stub it so component
 * tests that import authStore do not crash.
 */
if (typeof window !== 'undefined' && !window.matchMedia) {
  window.matchMedia = (query: string) =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }) as unknown as MediaQueryList;
}
