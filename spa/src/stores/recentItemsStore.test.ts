import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useRecentItemsStore } from './recentItemsStore';
import { useAuthStore } from './authStore';

const KEY = 'ogami:recent-items';

/** The shape zustand's `persist` + `createJSONStorage` actually writes. */
function envelope(items: unknown[], ownerId: string | null = 'user-a') {
  return JSON.stringify({ state: { items, ownerId }, version: 1 });
}

const row = { url: '/hr/employees/abc', label: 'OGM-2026-0142', ts: 1_700_000_000_000 };

describe('recentItemsStore persistence', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
  });

  /*
   * Regression. `persistedSchema` validated `{ items }` — the STATE shape —
   * against what `createJSONStorage` writes, which is `{ state, version }`.
   * `items` was therefore never a top-level key, the parse failed on every
   * page load, and the guard deleted the key it was meant to protect. The
   * store looked fine and persisted nothing.
   */
  it('rehydrates a list it previously wrote', async () => {
    localStorage.setItem(KEY, envelope([row]));
    vi.resetModules();

    const { useRecentItemsStore: fresh } = await import('./recentItemsStore');

    expect(fresh.getState().items).toHaveLength(1);
    expect(fresh.getState().items[0].label).toBe('OGM-2026-0142');
    expect(localStorage.getItem(KEY)).not.toBeNull();
  });

  it('still discards a malformed blob', async () => {
    localStorage.setItem(KEY, envelope([{ nope: true }]));
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.resetModules();

    const { useRecentItemsStore: fresh } = await import('./recentItemsStore');

    expect(fresh.getState().items).toEqual([]);
    expect(localStorage.getItem(KEY)).toBeNull();
  });
});

/*
 * M009-F02. Ogami runs on shared terminals. `authStore.logout()` clears the
 * query cache and `ogami:formdraft:*` but never this store, so the next person
 * to press ⌘K saw the previous user's employee numbers, PO numbers and customer
 * names — with working links to records their own role forbids.
 */
describe('recentItemsStore ownership', () => {
  beforeEach(() => {
    localStorage.clear();
    useAuthStore.setState({ user: null });
    useRecentItemsStore.setState({ items: [], ownerId: null });
  });

  it('discards a list claimed by a different user', () => {
    useRecentItemsStore.setState({ items: [row], ownerId: 'user-a' });

    useRecentItemsStore.getState().claim('user-b');

    expect(useRecentItemsStore.getState().items).toEqual([]);
    expect(useRecentItemsStore.getState().ownerId).toBe('user-b');
  });

  it('keeps the list when the same user re-bootstraps', () => {
    useRecentItemsStore.setState({ items: [row], ownerId: 'user-a' });

    useRecentItemsStore.getState().claim('user-a');

    expect(useRecentItemsStore.getState().items).toHaveLength(1);
  });

  it('clears the list when a signed-in session ends', () => {
    useAuthStore.setState({ user: { id: 'user-a' } as never });
    useRecentItemsStore.setState({ items: [row], ownerId: 'user-a' });

    useAuthStore.setState({ user: null });

    expect(useRecentItemsStore.getState().items).toEqual([]);
  });

  it('clears the list when a different account takes over the terminal', () => {
    useAuthStore.setState({ user: { id: 'user-a' } as never });
    useRecentItemsStore.setState({ items: [row], ownerId: 'user-a' });

    // The 401 path hard-navigates without ever calling logout(), so claiming on
    // sign-in — not only clearing on sign-out — is what closes the hole.
    useAuthStore.setState({ user: { id: 'user-b' } as never });

    expect(useRecentItemsStore.getState().items).toEqual([]);
    expect(useRecentItemsStore.getState().ownerId).toBe('user-b');
  });
});
