import { create } from 'zustand';

export type ThemeMode = 'light' | 'dark' | 'system';

interface ThemeState {
  /** User-selected mode (light / dark / system). */
  mode: ThemeMode;
  /** Effective theme after resolving 'system' against prefers-color-scheme. */
  resolvedTheme: 'light' | 'dark';
  /** Updates the mode, applies the data-theme attribute, and (later) syncs to the API. */
  setMode: (mode: ThemeMode) => void;
  /** Initializes the store from an authenticated user's preference. */
  init: (initialMode?: ThemeMode) => void;
  /** Internally re-evaluates resolvedTheme and toggles <html data-theme>. */
  apply: () => void;
}

const systemPrefersDark = (): boolean =>
  typeof window !== 'undefined' &&
  window.matchMedia('(prefers-color-scheme: dark)').matches;

const resolveTheme = (mode: ThemeMode): 'light' | 'dark' => {
  if (mode === 'system') return systemPrefersDark() ? 'dark' : 'light';
  return mode;
};

const applyToDocument = (theme: 'light' | 'dark') => {
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('data-theme', theme);
  }
};

// ─── Media-query listener bookkeeping (module-level, outside Zustand state) ───
let mediaQuery: MediaQueryList | null = null;
let mediaHandler: (() => void) | null = null;

function detachSystemListener() {
  if (mediaQuery && mediaHandler) {
    mediaQuery.removeEventListener('change', mediaHandler);
    mediaQuery = null;
    mediaHandler = null;
  }
}

function attachSystemListener(callback: () => void) {
  detachSystemListener();
  if (typeof window !== 'undefined') {
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const handler = () => callback();
    mq.addEventListener('change', handler);
    mediaQuery = mq;
    mediaHandler = handler;
  }
}

export const useThemeStore = create<ThemeState>((set, get) => ({
  mode: 'system',
  resolvedTheme: typeof window === 'undefined' ? 'light' : resolveTheme('system'),

  setMode: (mode) => {
    const resolvedTheme = resolveTheme(mode);
    applyToDocument(resolvedTheme);
    set({ mode, resolvedTheme });

    if (mode === 'system') {
      attachSystemListener(() => get().apply());
    } else {
      detachSystemListener();
    }

    // Server-side persistence is wired in Task 9 once auth lands —
    // we fire-and-forget to avoid coupling the theme to the auth boot.
    if (typeof window !== 'undefined') {
      void import('@/api/auth')
        .then((mod) => mod.authApi?.updatePreferences?.({ theme_mode: mode }))
        .catch(() => {
          /* Silent — preferences will sync next auth bootstrap. */
        });
    }
  },

  init: (initialMode) => {
    const mode = initialMode ?? 'system';
    const resolvedTheme = resolveTheme(mode);
    applyToDocument(resolvedTheme);
    set({ mode, resolvedTheme });

    if (mode === 'system') {
      attachSystemListener(() => get().apply());
    } else {
      detachSystemListener();
    }
  },

  apply: () => {
    const { mode } = get();
    const resolvedTheme = resolveTheme(mode);
    applyToDocument(resolvedTheme);
    set({ resolvedTheme });
  },
}));
