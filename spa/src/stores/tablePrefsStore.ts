import { create } from 'zustand';
import { authApi } from '@/api/auth';

export type TableDensity = 'compact' | 'default' | 'spacious';

export interface TablePrefs {
  density?: TableDensity;
  hiddenColumns?: string[];
  columnWidths?: Record<string, number>;
}

interface TablePrefsState {
  userId: string | null;
  byTable: Record<string, TablePrefs>;
  hydrate: (userId: string, byTable?: Record<string, TablePrefs>) => void;
  clear: () => void;
  setDensity: (tableKey: string, density: TableDensity) => void;
  setHiddenColumns: (tableKey: string, hidden: string[]) => void;
  setColumnWidths: (tableKey: string, widths: Record<string, number>) => void;
  reset: (tableKey: string) => void;
}

export const useTablePrefsStore = create<TablePrefsState>((set, get) => ({
  userId: null,
  byTable: {},
  hydrate: (userId, byTable = {}) => set({ userId, byTable }),
  clear: () => set({ userId: null, byTable: {} }),
  setDensity: (tableKey, density) => {
    set((state) => ({
      byTable: {
        ...state.byTable,
        [tableKey]: { ...(state.byTable[tableKey] ?? {}), density },
      },
    }));
    if (get().userId) {
      void authApi.updatePreferences({ table_preferences: { [tableKey]: { density } } }).catch(() => {
        /* The local choice remains active; the next session load syncs from the server. */
      });
    }
  },
  setHiddenColumns: (tableKey, hiddenColumns) => {
    set((state) => ({
      byTable: {
        ...state.byTable,
        [tableKey]: { ...(state.byTable[tableKey] ?? {}), hiddenColumns },
      },
    }));
    if (get().userId) {
      void authApi.updatePreferences({ table_preferences: { [tableKey]: { hiddenColumns } } }).catch(() => {
        /* The local choice remains active; the next session load syncs from the server. */
      });
    }
  },
  setColumnWidths: (tableKey, columnWidths) => {
    set((state) => ({
      byTable: {
        ...state.byTable,
        [tableKey]: { ...(state.byTable[tableKey] ?? {}), columnWidths },
      },
    }));
    if (get().userId) {
      void authApi.updatePreferences({ table_preferences: { [tableKey]: { columnWidths } } }).catch(() => {
        /* The local choice remains active; the next session load syncs from the server. */
      });
    }
  },
  reset: (tableKey) => {
    const next = { ...get().byTable };
    delete next[tableKey];
    set({ byTable: next });
    if (get().userId) {
      void authApi.updatePreferences({ table_preferences: { [tableKey]: null } }).catch(() => {
        /* The local choice remains active; the next session load syncs from the server. */
      });
    }
  },
}));
