// Recently visited pages/records — powers the ⌘K palette's "Recent" group.
//
// Persisted to localStorage. Entries include labels and optional sublabels
// alongside record URLs, so the stored list is scoped to
// both its owner and the server-provided access scope. It is capped at
// MAX_ITEMS, most-recent-first, and deduped by URL.
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
// localStorage has no expiry and no permission check, so the list is tied to
// an OWNER and an opaque access-scope fingerprint — see `claim()` below.

import { create } from 'zustand';
import { persist, createJSONStorage, type StateStorage } from 'zustand/middleware';
import { z } from 'zod';
import type { AuthUser } from '@/api/auth';
import { useAuthStore } from './authStore';

export interface RecentItem {
 url: string;
 label: string;
 /** Muted second line (e.g. customer name, section name). */
 sublabel?: string | null;
 /** Palette group type ('sales_order', 'page', …) — picks the icon. */
 type?: string | null;
 /** Epoch ms of last visit. */
 ts: number;
}

export const MAX_RECENT_ITEMS = 8;
const RECENT_ITEM_TTL_MS = 30 * 24 * 60 * 60 * 1000;

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
 /** Opaque fingerprint of the access scope that produced the visible labels. */
 scopeKey: string | null;
 add: (item: Omit<RecentItem, 'ts'>) => void;
 clear: () => void;
 resetForAuthChange: () => void;
 /**
  * Assert who and what access scope the list belongs to, discarding it when
  * either changes.
  *
  * Called on every auth-state change (below). Clearing on *logout* alone would
  * miss the 401 path, where the axios interceptor hard-navigates to /sign-in
  * without ever running `logout()`; claiming on *login* catches that too,
  * because whoever signs in next must claim the list before it renders.
  */
 claim: (ownerId: string | null, scopeKey: string | null) => void;
}

const recentItemSchema = z.object({
 url: z.string().min(1),
 label: z.string().min(1),
 sublabel: z.string().nullish(),
 // Accepted only to migrate older persisted entries; never returned as recent state.
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
  scopeKey: z.string().nullish(),
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
 if (result.success) {
  const current = result.data;
  const retained = current.state.items.filter((item) => item.ts >= Date.now() - RECENT_ITEM_TTL_MS);
  const hadStatusSnapshot = retained.some((item) => Object.prototype.hasOwnProperty.call(item, 'status'));
  const items = retained.map((item) => {
   const withoutStatus = { ...item };
   delete withoutStatus.status;
   return withoutStatus;
  });
  if (items.length !== current.state.items.length || hadStatusSnapshot) {
   const refreshed = JSON.stringify({
    ...current,
    state: { ...current.state, items },
   });
   localStorage.setItem(name, refreshed);
   return refreshed;
  }
  return raw;
 }
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
 scopeKey: null,
 add: (item) =>
 set((s) => ({
 items: [
 { ...item, ts: Date.now() },
 ...s.items.filter((i) => i.url !== item.url),
 ].slice(0, MAX_RECENT_ITEMS),
 })),
 clear: () => set({ items: [] }),
 resetForAuthChange: () => set({ items: [], ownerId: null, scopeKey: null }),
 claim: (ownerId, scopeKey) =>
  set((s) => (
   s.ownerId === ownerId && s.scopeKey === scopeKey
    ? { ownerId, scopeKey }
    : { items: [], ownerId, scopeKey }
  )),
 }),
 { name: 'ogami:recent-items', version: 1, storage: createJSONStorage(() => safeStorage) },
 ),
);

/**
 * Bind the list to the signed-in identity.
 *
 * Subscribing here rather than adding another cross-store call to
 * `authStore.logout()` keeps the rule next to the data it protects — and
 * covers the paths that never reach `logout()` at all. Note the asymmetry: a
 * present user claims the list using an owner and access-scope fingerprint.
 * A page reload keeps the same user's list only if the server returns the same
 * role, permissions, delegations, employee scope and enabled modules. The
 * momentary `user === null` during bootstrap does not wipe a legitimate
 * returning session; the list is checked once `/me` resolves.
 */
function accessScopeFingerprint(user: AuthUser): string {
 const payload = JSON.stringify({
  role: [user.role?.id, user.role?.slug],
  employee: [user.employee?.id, user.employee?.department_id],
  active: user.is_active,
  permissions: [...user.permissions].sort(),
  delegatedApprovals: [...user.delegated_approvals].sort(),
  features: [...user.features].sort(),
 });

 // This is a local cache discriminator, not an authorization token. Keep raw
 // permission and feature names out of localStorage while changing the key
 // whenever the server-provided access scope changes.
 let first = 0x811c9dc5;
 let second = 0x9e3779b9;
 for (let index = 0; index < payload.length; index += 1) {
  const code = payload.charCodeAt(index);
  first = Math.imul(first ^ code, 0x01000193);
  second = Math.imul(second ^ (code + index), 0x85ebca6b);
 }

 return `${(first >>> 0).toString(16).padStart(8, '0')}${(second >>> 0).toString(16).padStart(8, '0')}`;
}

useAuthStore.subscribe((state, prev) => {
 const user = state.user;
 const owner = user?.id ?? null;
 if (user) {
 useRecentItemsStore.getState().claim(owner, accessScopeFingerprint(user));
 } else if (
  prev.user !== null ||
  (prev.isLoading && !state.isLoading && state.bootstrapError === null)
 ) {
  // The second case is a completed 401/419 bootstrap from a cold page load.
  // No authenticated identity was present in `prev`, but persisted entries
  // still must not remain available after the session has expired.
 useRecentItemsStore.getState().resetForAuthChange();
 }
});
