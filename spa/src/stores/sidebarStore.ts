import { create } from 'zustand';

interface SidebarState {
  collapsed: boolean;
  toggle: () => void;
  setCollapsed: (collapsed: boolean) => void;
  init: (initial?: boolean) => void;
}

export const useSidebarStore = create<SidebarState>((set, get) => ({
  collapsed: false,

  toggle: () => {
    const next = !get().collapsed;
    set({ collapsed: next });

    if (typeof window !== 'undefined') {
      void import('@/api/auth')
        .then((mod) => mod.authApi?.updatePreferences?.({ sidebar_collapsed: next }))
        .catch(() => {
          /* Preference will sync on next bootstrap. */
        });
    }
  },

  setCollapsed: (collapsed) => set({ collapsed }),

  init: (initial = false) => {
    set({ collapsed: initial });
    // Auto-collapse on narrow viewports.
    if (typeof window !== 'undefined' && window.innerWidth < 1280) {
      set({ collapsed: true });
    }
  },
}));
