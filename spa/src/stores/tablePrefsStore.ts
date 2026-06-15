// Series X / Task X4 — per-user table preferences (density, hidden columns).
//
// Persisted to localStorage under a single key. **No PII** lives here — only
// column metadata and density choices. The store is keyed by `tableKey`
// (e.g. `hr.employees.list`); pages without a `tableKey` use in-memory
// session state from DataTable's existing useState.

import { create } from 'zustand';
import { persist, createJSONStorage, type StateStorage } from 'zustand/middleware';
import { z } from 'zod';

export type TableDensity = 'compact' | 'default' | 'spacious';

export interface TablePrefs {
  density?: TableDensity;
  hiddenColumns?: string[];
}

interface TablePrefsState {
  byTable: Record<string, TablePrefs>;
  setDensity: (tableKey: string, density: TableDensity) => void;
  setHiddenColumns: (tableKey: string, hidden: string[]) => void;
  reset: (tableKey: string) => void;
}

/** Validates the raw JSON pulled from localStorage. Corrupted / tampered state is discarded. */
const persistedSchema = z.object({
  byTable: z.record(
    z.object({
      density: z.enum(['compact', 'default', 'spacious']).optional(),
      hiddenColumns: z.array(z.string()).optional(),
    }).optional(),
  ),
});

const safeStorage: StateStorage = {
  getItem: (name) => {
    const raw = localStorage.getItem(name);
    if (!raw) return null;
    try {
      const parsed = JSON.parse(raw);
      const result = persistedSchema.safeParse(parsed);
      if (result.success) return raw;
      // eslint-disable-next-line no-console
      console.warn(`[tablePrefsStore] Invalid persisted state for "${name}", resetting.`, result.error.flatten());
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

export const useTablePrefsStore = create<TablePrefsState>()(
  persist(
    (set) => ({
      byTable: {},
      setDensity: (tableKey, density) =>
        set((s) => ({
          byTable: {
            ...s.byTable,
            [tableKey]: { ...(s.byTable[tableKey] ?? {}), density },
          },
        })),
      setHiddenColumns: (tableKey, hidden) =>
        set((s) => ({
          byTable: {
            ...s.byTable,
            [tableKey]: { ...(s.byTable[tableKey] ?? {}), hiddenColumns: hidden },
          },
        })),
      reset: (tableKey) =>
        set((s) => {
          const next = { ...s.byTable };
          delete next[tableKey];
          return { byTable: next };
        }),
    }),
    { name: 'ogami:table-prefs', version: 1, storage: createJSONStorage(() => safeStorage) },
  ),
);
