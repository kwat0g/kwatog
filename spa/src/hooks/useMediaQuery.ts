import { useEffect, useState } from 'react';

/**
 * One breakpoint query, asked in one place.
 *
 * The SPA styles almost everything with CSS (`sm:`, `md:`), which is the right
 * default and stays the default. This exists for the handful of decisions that
 * are not styling: whether `Modal` presents as a sheet, whether a control is
 * rendered touch-first. Those have to be decided in JS, and before this hook
 * each one would have invented its own `window.innerWidth` listener, its own
 * breakpoint number and its own resize handling.
 *
 * The query is written as a `max-width` so it agrees with Tailwind's `sm`
 * boundary (640px): `useIsPhone()` is true exactly when `sm:` is not applied.
 * Two sources of truth for "am I on a phone" is how a layout and its behaviour
 * end up disagreeing at 639px.
 */
export const PHONE_QUERY = '(max-width: 639px)';

/**
 * SSR/test-safe read.
 *
 * `matchMedia` does not exist in jsdom, and this app's entire unit suite runs in
 * jsdom — a hook that assumed it would throw a `TypeError` in ~1000 tests the
 * moment a page rendered a dialog. Missing media-query support means "not a
 * phone", which is the desktop presentation: the layout the tests assert, and
 * the one that does not depend on a viewport the test never set.
 */
function read(query: string): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia(query).matches;
}

export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() => read(query));

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
    const mql = window.matchMedia(query);
    const onChange = () => setMatches(mql.matches);
    /* Re-read on mount: the initial state was computed before the effect ran,
     * and the viewport may have changed in between (devtools, orientation). */
    onChange();
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, [query]);

  return matches;
}

/** True below Tailwind's `sm` (640px) — the phone presentation. */
export function useIsPhone(): boolean {
  return useMediaQuery(PHONE_QUERY);
}
