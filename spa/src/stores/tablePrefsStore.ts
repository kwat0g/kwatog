// Series X / Task X4 — per-user table preferences (density, hidden columns).
//
// Persisted to localStorage under a single key. **No PII** lives here — only
// column metadata and density choices. The store is keyed by `tableKey`
// (e.g. `hr.employees.list`); pages without a `tableKey` use in-memory
// session state from DataTable's existing useState.

import { create } from 'zustand';
import { persist } from 'zustand/middleware';

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
    { name: 'ogami:table-prefs', version: 1 },
  ),
);
