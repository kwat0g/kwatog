// Recently visited pages/records — powers the ⌘K palette's "Recent" group.
//
// Persisted to localStorage. **No PII beyond record identifiers** (e.g.
// "SO-202604-0003") — the same strings the user sees in the UI. Capped at
// MAX_ITEMS, most-recent-first, deduped by URL.

import { create } from 'zustand';
import { persist, createJSONStorage, type StateStorage } from 'zustand/middleware';
import { z } from 'zod';

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
 add: (item: Omit<RecentItem, 'ts'>) => void;
 clear: () => void;
}

const recentItemSchema = z.object({
 url: z.string().min(1),
 label: z.string().min(1),
 sublabel: z.string().nullish(),
 status: z.string().nullish(),
 type: z.string().nullish(),
 ts: z.number(),
});

const persistedSchema = z.object({
 items: z.array(recentItemSchema),
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
 add: (item) =>
 set((s) => ({
 items: [
 { ...item, ts: Date.now() },
 ...s.items.filter((i) => i.url !== item.url),
 ].slice(0, MAX_RECENT_ITEMS),
 })),
 clear: () => set({ items: [] }),
 }),
 { name: 'ogami:recent-items', version: 1, storage: createJSONStorage(() => safeStorage) },
 ),
);
