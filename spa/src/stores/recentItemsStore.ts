// Recently visited pages/records — powers the ⌘K palette's "Recent" group.
//
// Persisted to localStorage. **No PII beyond record identifiers** (e.g.
// "SO-202604-0003") — the same strings the user sees in the UI. Capped at
// MAX_ITEMS, most-recent-first, deduped by URL.
//
// That claim depends on the SERVER, not on this file: `sublabel` is whatever
// `GET /search` returned, copied verbatim by `CommandPalette.pick()`. It used
// to carry a customer's or vendor's `tin` whenever `contact_person` was empty,
// so a government identifier outlived the session in browser storage. Anything
// added to the search result contract is therefore added to this file's threat
// model too: keep sensitive fields out of `sublabel` at the source (see
// GlobalSearchService, M009-F02), because there is no way to un-persist them
// from here.
//
// localStorage has no expiry and no permission check, so the list is also
// tied to an OWNER — see `claim()` below.

import { create } from 'zustand';
import { persist, createJSONStorage, type StateStorage } from 'zustand/middleware';
import { z } from 'zod';
import { useAuthStore } from './authStore';

export interface RecentItem {
 url: string;
 label: string;
 /** Muted second line (e.g. customer name, section name). */
 sublabel?: string | null;
 /** Record status — rendered as a chip when present. */
 status?: string | null;
 /** Palette group type ('sales_order', 'page', …) — picks the icon. */
 type?: string | null;
 /** Epoch ms of last visit. */
 ts: number;
}

export const MAX_RECENT_ITEMS = 8;

interface RecentItemsState {
 items: RecentItem[];
 /**
  * Hash id of the user the list belongs to, persisted alongside it.
  *
  * Ogami runs on shared terminals (plant office, QC bench). `authStore.logout()`
  * wipes the query cache and `ogami:formdraft:*` keys for exactly this reason,
  * but it never touched this store — so the next person to sign in opened ⌘K
  * onto the previous user's employee numbers, PO numbers and customer names,
  * complete with working links to records their own role forbids. The 403 on
  * click is not the leak; the identifiers already rendered.
  */
 ownerId: string | null;
 add: (item: Omit<RecentItem, 'ts'>) => void;
 clear: () => void;
 /**
  * Assert who the list belongs to, discarding it if that is someone else.
  *
  * Called on every auth-state change (below). Clearing on *logout* alone would
  * miss the 401 path, where the axios interceptor hard-navigates to /login
  * without ever running `logout()`; claiming on *login* catches that too,
  * because whoever signs in next must claim the list before it renders.
  */
 claim: (ownerId: string | null) => void;
}

const recentItemSchema = z.object({
 url: z.string().min(1),
 label: z.string().min(1),
 sublabel: z.string().nullish(),
 status: z.string().nullish(),
 type: z.string().nullish(),
 ts: z.number(),
});

/**
 * Shape of the value zustand's `persist` actually writes.
 *
 * This used to be `z.object({ items: […] })`, matching the STATE rather than
 * the stored envelope. `createJSONStorage` stores `{ state, version }`, so
 * `items` was never a top-level key: the parse failed on every page load, the
 * store logged "Invalid persisted state" to the console and deleted the key.
 * The net effect was that persistence silently did not work at all — the
 * "Recent" group was empty after any reload, which reads as "I have not opened
 * anything" rather than as a bug. Validating the envelope is what makes the
 * guard do its job.
 */
const persistedSchema = z.object({
 state: z.object({
  items: z.array(recentItemSchema),
  ownerId: z.string().nullish(),
 }),
 version: z.number().optional(),
});

const safeStorage: StateStorage = {
 getItem: (name) => {
 const raw = localStorage.getItem(name);
 if (!raw) return null;
 try {
 const parsed = JSON.parse(raw);
 const result = persistedSchema.safeParse(parsed);
 if (result.success) return raw;
 console.warn(`[recentItemsStore] Invalid persisted state for "${name}", resetting.`, result.error.flatten());
 localStorage.removeItem(name);
 return null;
 } catch {
 localStorage.removeItem(name);
 return null;
 }
 },
 setItem: (name, value) => localStorage.setItem(name, value),
 removeItem: (name) => localStorage.removeItem(name),
};

export const useRecentItemsStore = create<RecentItemsState>()(
 persist(
 (set) => ({
 items: [],
 ownerId: null,
 add: (item) =>
 set((s) => ({
 items: [
 { ...item, ts: Date.now() },
 ...s.items.filter((i) => i.url !== item.url),
 ].slice(0, MAX_RECENT_ITEMS),
 })),
 clear: () => set({ items: [] }),
 claim: (ownerId) =>
 set((s) => (s.ownerId === ownerId ? { ownerId } : { items: [], ownerId })),
 }),
 { name: 'ogami:recent-items', version: 1, storage: createJSONStorage(() => safeStorage) },
 ),
);

/**
 * Bind the list to the signed-in identity.
 *
 * Subscribing here rather than adding a fourth cross-store call to
 * `authStore.logout()` keeps the rule next to the data it protects — and
 * covers the paths that never reach `logout()` at all. Note the asymmetry: a
 * present user *claims* (a page reload re-bootstraps the same id and keeps its
 * own list), while only a transition away from a signed-in user *clears* — so
 * the momentary `user === null` during bootstrap does not wipe a legitimate
 * returning session.
 */
useAuthStore.subscribe((state, prev) => {
 const owner = state.user?.id ?? null;
 if (owner !== null) {
 useRecentItemsStore.getState().claim(owner);
 } else if (prev.user !== null) {
 useRecentItemsStore.getState().clear();
 }
});
