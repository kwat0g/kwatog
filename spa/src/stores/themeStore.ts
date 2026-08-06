import { create } from 'zustand';
import { authApi } from '@/api/auth';

export type ThemeMode = 'light' | 'dark' | 'system';
/** What actually lands on <html data-theme>. `floor` is route-forced, never chosen. */
export type AppliedTheme = 'light' | 'dark' | 'floor';

interface ThemeState {
 /** User-selected mode (light / dark / system). */
 mode: ThemeMode;
 /** Effective theme after resolving 'system' against prefers-color-scheme. */
 resolvedTheme: 'light' | 'dark';
 /** Non-null while a route forces a palette. Suppresses mode-driven application. */
 override: AppliedTheme | null;
 /** Updates the mode, applies the data-theme attribute, and (later) syncs to the API. */
 setMode: (mode: ThemeMode) => void;
 /** Initializes the store from an authenticated user's preference. */
 init: (initialMode?: ThemeMode) => void;
 /** Internally re-evaluates resolvedTheme and toggles <html data-theme>. */
 apply: () => void;
 /** Forces a palette for the current route. Idempotent. */
 pushOverride: (theme: AppliedTheme) => void;
 /** Releases the override and restores the user's preference. Safe to call unpaired. */
 popOverride: () => void;
}

const systemPrefersDark = (): boolean =>
 typeof window !== 'undefined' &&
 window.matchMedia('(prefers-color-scheme: dark)').matches;

const resolveTheme = (mode: ThemeMode): 'light' | 'dark' => {
 if (mode === 'system') return systemPrefersDark() ? 'dark' : 'light';
 return mode;
};

const applyToDocument = (theme: AppliedTheme) => {
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
 // Light is the default for new sessions; an authenticated user's stored
 // preference (light / dark / system) overrides this via init().
 mode: 'light',
 resolvedTheme: 'light',
 override: null,

 setMode: (mode) => {
 const resolvedTheme = resolveTheme(mode);
 if (get().override === null) applyToDocument(resolvedTheme);
 set({ mode, resolvedTheme });

 if (mode === 'system') {
 attachSystemListener(() => get().apply());
 } else {
 detachSystemListener();
 }

 // Server-side persistence is wired in Task 9 once auth lands —
 // we fire-and-forget to avoid coupling the theme to the auth boot.
 if (typeof window !== 'undefined') {
 void authApi.updatePreferences({ theme_mode: mode }).catch(() => {
 /* Silent — preferences will sync next auth bootstrap. */
 });
 }
 },

 init: (initialMode) => {
 const mode = initialMode ?? 'light';
 const resolvedTheme = resolveTheme(mode);
 if (get().override === null) applyToDocument(resolvedTheme);
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
 if (get().override === null) applyToDocument(resolvedTheme);
 set({ resolvedTheme });
 },

 pushOverride: (theme) => {
 // Idempotent: re-pushing the same override must not disturb anything.
 if (get().override === theme) return;
 applyToDocument(theme);
 set({ override: theme });
 },

 popOverride: () => {
 if (get().override === null) return;
 set({ override: null });
 // resolvedTheme already tracks the user's preference — setMode and the
 // system listener keep it current even while overridden.
 applyToDocument(get().resolvedTheme);
 },
}));
