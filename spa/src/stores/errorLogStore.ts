import { create } from 'zustand';

/**
 * Captures the last N HTTP errors so the floating Dev panel can surface
 * Laravel's exception details (class, file, line, trace) inline in the SPA
 * without forcing the user to dig through `storage/logs/laravel.log`.
 *
 * Active in any environment but the panel is mounted only when
 * `import.meta.env.DEV` is true (or when `VITE_SHOW_DEV_ERRORS=1`).
 */

export interface ServerErrorEntry {
  id: string;
  timestamp: string;
  method: string;
  url: string;
  status: number | null;
  message: string;
  exception?: string;
  file?: string;
  line?: number;
  trace?: Array<{ file?: string; line?: number; function?: string; class?: string }>;
  raw?: unknown;
}

const MAX_ENTRIES = 25;

interface ErrorLogState {
  entries: ServerErrorEntry[];
  unreadCount: number;
  push: (entry: Omit<ServerErrorEntry, 'id' | 'timestamp'>) => void;
  clear: () => void;
  markRead: () => void;
}

export const useErrorLogStore = create<ErrorLogState>((set) => ({
  entries: [],
  unreadCount: 0,
  push: (e) => set((s) => {
    const entry: ServerErrorEntry = {
      ...e,
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      timestamp: new Date().toISOString(),
    };
    return {
      entries: [entry, ...s.entries].slice(0, MAX_ENTRIES),
      unreadCount: s.unreadCount + 1,
    };
  }),
  clear: () => set({ entries: [], unreadCount: 0 }),
  markRead: () => set({ unreadCount: 0 }),
}));
